<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbox\Service;

use Carbon\CarbonImmutable;
use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Logging\LoggerChannels;
use Hyperf\Apex\Outbox\Contract\OutboxHandlerRegistryInterface;
use Hyperf\Apex\Outbox\DTO\OutboxClaimDTO;
use Hyperf\Apex\Outbox\DTO\OutboxHandleResultDTO;
use Hyperf\Apex\Outbox\DTO\OutboxMessageDTO;
use Hyperf\Apex\Outbox\Enum\OutboxEventStatus;
use Hyperf\Apex\Outbox\Enum\OutboxHandleOutcome;
use Hyperf\Apex\Outbox\Repository\Contract\OutboxEventRepositoryInterface;
use Hyperf\Apex\Support\Time;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Throwable;

use function mb_strcut;

/**
 * 发件箱投递：认领一批 → 执行 handler → 按结果写回状态。
 *
 * 三条不变量：
 * - **认领要先于执行**：写进 `processing` 并带租约，别的 worker 才抢不走同一条事件；
 * - **写回要带令牌**：租约过期后被接管的事件，旧 worker 的结果会被 fencing 丢弃，
 *   不会出现「两个 worker 各写一半」的状态；
 * - **失败要停得下来**：可重试失败按指数退避排下次尝试，次数用尽进死信，
 *   不会无限重试毒消息（认领即消耗一次尝试，崩溃的 worker 也计入）。
 */
final class OutboxDeliveryService
{
    /** 单次清理的行数上限，避免大表清理长时间持锁。 */
    private const PRUNE_BATCH_SIZE = 1000;

    /** 单次清理最多跑几批，避免一次调度把清理做成了常驻任务。 */
    private const MAX_PRUNE_BATCHES = 1000;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly OutboxEventRepositoryInterface $repository,
        private readonly OutboxHandlerRegistryInterface $handlers,
        private readonly ConfigInterface $config,
        LoggerFactory $loggerFactory,
    ) {
        $this->logger = LoggerChannels::resolve($loggerFactory, 'outbox', 'error');
    }

    /**
     * 认领并投递一批到期事件，返回本轮**认领**的条数。
     *
     * 认领数不等于实际执行数：批内逐条处理时，若某条的租约在轮到它之前已经失效
     * （被其它 worker 接管），那一条会被跳过（见 deliverOne）。
     *
     * @param int $limit 单轮上限；调用方（消费进程 / 手工执行）按积压量决定
     */
    public function deliverBatch(int $limit): int
    {
        if (! (bool) $this->config->get('outbox.enabled', true)) {
            return 0;
        }

        $now = Time::now();
        $claims = $this->repository->claimDue(
            $now->toDateTimeString(),
            max(1, $limit),
            bin2hex(random_bytes(16)),
            $now->addSeconds($this->lockSeconds())->toDateTimeString(),
        );

        foreach ($claims as $claim) {
            $this->deliverOne($claim);
        }

        return count($claims);
    }

    /**
     * 投递单条已认领的事件。
     *
     * 返回 false 有两种来源，语义都是「本次结果作废」，都是正常并发路径而非错误：
     * - 执行前续租失败：事件已被别的 worker 接管，本次连 handler 都不该执行；
     * - 写回被 fencing 拦下：投递期间租约过期，本次结果已经不是权威结果。
     */
    public function deliverOne(OutboxClaimDTO $claim): bool
    {
        $message = $claim->message;

        /*
         * 执行前续租并确认自己仍持有租约。
         *
         * 整批事件共用认领时写下的那一个租约，而批内是逐条串行处理的：处理到靠后的条目时，
         * 原始租约可能已经过期、事件已被别的 worker 重新认领。此时若照样执行 handler，
         * 同一条事件的副作用会发生两次 —— fencing 只保证写回只有一份，拦不住副作用。
         */
        $renewedAt = Time::now();
        if (! $this->repository->renewLease(
            $message->id,
            $claim->lockToken,
            $renewedAt->addSeconds($this->lockSeconds())->toDateTimeString(),
            $renewedAt->toDateTimeString(),
        )) {
            $this->logger->warning('Outbox 事件租约已失效（可能已被其他 worker 接管），跳过本次执行。', [
                'id' => $message->id,
                'event_type' => $message->eventType,
            ]);

            return false;
        }

        $result = $this->handle($message);
        $now = Time::now();
        $nowString = $now->toDateTimeString();

        $stored = match ($result->outcome) {
            OutboxHandleOutcome::SUCCESS => $this->repository->markSucceeded(
                $message->id,
                $claim->lockToken,
                $this->truncate($result->resultSummary),
                $nowString,
            ),
            OutboxHandleOutcome::PERMANENT => $this->repository->markTerminal(
                $message->id,
                $claim->lockToken,
                OutboxEventStatus::FAILED,
                'permanent',
                $this->truncate($result->message),
                $nowString,
            ),
            OutboxHandleOutcome::RETRYABLE => $this->scheduleRetry($claim, $result, $now),
        };

        if (! $stored) {
            $this->logger->warning('Outbox 事件已被其他 worker 接管，本次结果未写回。', [
                'id' => $message->id,
                'event_type' => $message->eventType,
            ]);
        }

        return $stored;
    }

    /**
     * 人工重放一条失败的事件。
     *
     * 只允许重放已经停下来的失败（永久失败 / 死信）：待执行与执行中的重放等于让同一条事件
     * 被投递两次，已成功的重放会重复产生下游副作用。
     */
    public function replay(int $id): void
    {
        $event = $this->repository->find($id)
            ?? throw new BusinessException(ErrorCode::RESOURCE_NOT_FOUND, sprintf('Outbox 事件 %d 不存在。', $id));

        $status = OutboxEventStatus::tryFrom($event->status);
        if ($status === null || ! $status->canReplay()) {
            throw new BusinessException(
                ErrorCode::RESOURCE_CONFLICT,
                sprintf('Outbox 事件 %d 当前状态为 %s，不允许重放。', $id, $event->status),
            );
        }

        if (! $this->repository->replay($id, Time::now()->toDateTimeString())) {
            // 校验与写入之间状态被改了（例如别的 worker 刚接管）：以库里的状态为准，不硬写。
            throw new BusinessException(ErrorCode::RESOURCE_CONFLICT, sprintf('Outbox 事件 %d 状态已变化，未执行重放。', $id));
        }
    }

    /**
     * 清理已成功的历史事件，返回删除条数。
     *
     * `outbox.retention_days` 为 0 表示不清理；失败与死信永不自动删除 ——
     * 它们需要人工看过之后再决定重放还是放弃。
     */
    public function prune(): int
    {
        $days = (int) $this->config->get('outbox.retention_days', 0);
        if ($days <= 0) {
            return 0;
        }

        $before = Time::now()->subDays($days)->toDateTimeString();
        $deleted = 0;
        for ($batch = 0; $batch < self::MAX_PRUNE_BATCHES; ++$batch) {
            $count = $this->repository->deleteSucceededBefore($before, self::PRUNE_BATCH_SIZE);
            $deleted += $count;
            if ($count < self::PRUNE_BATCH_SIZE) {
                break;
            }
        }

        return $deleted;
    }

    private function scheduleRetry(OutboxClaimDTO $claim, OutboxHandleResultDTO $result, CarbonImmutable $now): bool
    {
        $message = $claim->message;
        $error = $this->truncate($result->message);

        if ($message->attemptCount >= $message->maxAttempts) {
            // 次数用尽 → 死信：保留 last_error 与 fail_time，人工排查后再决定是否重放。
            return $this->repository->markTerminal(
                $message->id,
                $claim->lockToken,
                OutboxEventStatus::DEAD_LETTER,
                'retryable',
                $error,
                $now->toDateTimeString(),
            );
        }

        // handler 明确给的下次时间优先；给 0 或负数按「至少等 1 秒」处理，避免立刻重试打转。
        $delay = $result->retryAfterSeconds !== null
            ? max(1, $result->retryAfterSeconds)
            : $this->backoffSeconds($message->attemptCount);

        return $this->repository->scheduleRetry(
            $message->id,
            $claim->lockToken,
            'retryable',
            $error,
            $now->addSeconds($delay)->toDateTimeString(),
            $now->toDateTimeString(),
        );
    }

    private function handle(OutboxMessageDTO $message): OutboxHandleResultDTO
    {
        $handler = $this->handlers->find($message->eventType);
        if ($handler === null) {
            /*
             * 没注册 handler 按「可重试」处理，不当作永久失败：缺注册最常见的原因是发布顺序
             * （消费进程先起来、handler 的部署还没跟上），下一次尝试就能自愈。
             * 一直没注册的话会在尝试次数用尽后进死信 —— 既不无限重试，也不静默丢事件。
             *
             * 级别取 warning：发布窗口里这属于预期内的过渡状态，用 error 会把发布期的日志刷满，
             * 真正的错误反而被淹没；不会无限重试这一点已由尝试次数上限保证。
             */
            $this->logger->warning('Outbox 事件没有注册 handler。', [
                'id' => $message->id,
                'event_type' => $message->eventType,
            ]);

            return OutboxHandleResultDTO::retryable(sprintf('事件类型 %s 没有注册 handler', $message->eventType));
        }

        try {
            return $handler->handle($message);
        } catch (Throwable $throwable) {
            /*
             * handler 抛异常按可重试处理：投递失败大多是下游抖动。
             * 「不可恢复」这件事异常里看不出来，只能由 handler 返回 PERMANENT 明确表达。
             */
            $this->logger->error('Outbox handler 执行失败。', [
                'id' => $message->id,
                'event_type' => $message->eventType,
                'exception' => $throwable,
            ]);

            return OutboxHandleResultDTO::retryable($throwable->getMessage());
        }
    }

    /**
     * 指数退避 + 抖动。
     *
     * `base * 2^(attempt-1)`，上限 `backoff_max_seconds`；抖动 ±ratio，
     * 避免同一批事件退避后又同时涌向下游（退避本身会把它们排到同一秒）。
     */
    private function backoffSeconds(int $attemptCount): int
    {
        $base = max(1, (int) $this->config->get('outbox.backoff_base_seconds', 30));
        $max = max($base, (int) $this->config->get('outbox.backoff_max_seconds', 1800));
        $ratio = min(1.0, max(0.0, (float) $this->config->get('outbox.backoff_jitter_ratio', 0.2)));

        // 指数先夹到 2^30 以内：attemptCount 只受 max_attempts 约束，但配置可以被改大。
        $exponent = min(30, max(0, $attemptCount - 1));
        $delay = (int) min($max, $base * (2 ** $exponent));
        $jitter = (int) round($delay * $ratio);

        return max(1, $delay + ($jitter === 0 ? 0 : random_int(-$jitter, $jitter)));
    }

    private function lockSeconds(): int
    {
        return max(1, (int) $this->config->get('outbox.lock_seconds', 300));
    }

    /**
     * 错误与结果摘要按 `outbox.error_max_length` 截断：列是 text，别让一条超长异常把整行撑爆。
     *
     * 口径是**字节**而不是字符：列上限本身就是字节（text = 65535 字节），按字符数算在中文
     * 内容下会超。用 mb_strcut 截断，保证不会切碎多字节字符（与 WecomRobotChannel 同一口径）。
     */
    private function truncate(?string $message): ?string
    {
        $max = (int) $this->config->get('outbox.error_max_length', 4000);
        if ($message === null || $max <= 0) {
            return $message;
        }

        return strlen($message) > $max ? mb_strcut($message, 0, $max) . '…' : $message;
    }
}
