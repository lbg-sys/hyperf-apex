<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbox;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Outbox\Contract\OutboxEventInterface;
use Hyperf\Apex\Outbox\Contract\OutboxPublisherInterface;
use Hyperf\Apex\Outbox\DTO\OutboxPublishResultDTO;
use Hyperf\Apex\Outbox\Enum\OutboxEventStatus;
use Hyperf\Apex\Outbox\Repository\Contract\OutboxEventRepositoryInterface;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\Exception\UniqueConstraintViolationException;
use RuntimeException;

/**
 * 事务发件箱的入队端。
 *
 * 调用点在业务事务内，所以这里只做一件事：把事件写进 `outbox_events`。
 * 真正的投递由独立进程在事务提交后完成 —— 事务内做远程调用会拖长行锁持有时间，
 * 失败还会把业务写入一起回滚。
 *
 * 幂等靠唯一键 `(scope_key, event_type, idempotency_key)`：同一逻辑事件重复发布只会有一条记录。
 * 唯一键冲突用捕获异常的方式表达（与 `IdempotencyService` 同一取舍，目标是 MySQL）。
 */
final class OutboxPublisher implements OutboxPublisherInterface
{
    public function __construct(
        private readonly OutboxEventRepositoryInterface $repository,
        private readonly ConfigInterface $config,
    ) {
    }

    public function publish(OutboxEventInterface $event): OutboxPublishResultDTO
    {
        $payload = $event->payload();
        $payloadHash = hash('sha256', self::canonicalJson($payload));

        try {
            $created = $this->repository->create([
                'scope_key' => $event->scopeKey(),
                'event_type' => $event->eventType(),
                'idempotency_key' => $event->idempotencyKey(),
                'aggregate_type' => $event->aggregateType(),
                'aggregate_id' => $event->aggregateId(),
                'payload' => $payload,
                'payload_hash' => $payloadHash,
                'status' => OutboxEventStatus::PENDING->value,
                'attempt_count' => 0,
                'max_attempts' => $this->maxAttempts($event->eventType()),
            ]);

            return new OutboxPublishResultDTO((int) $created->id, true);
        } catch (UniqueConstraintViolationException) {
            // 唯一键冲突是正常并发路径：同一逻辑事件已经入队，下面按已有记录处理。
        }

        $existing = $this->repository->findForKey($event->scopeKey(), $event->eventType(), $event->idempotencyKey())
            ?? throw new RuntimeException('Outbox 事件唯一键冲突后无法读取记录。');

        if (! hash_equals($existing->payload_hash, $payloadHash)) {
            /*
             * 幂等键相同而载荷不同，说明调用方把幂等键用错了（例如用固定的业务名代替了唯一标识）。
             * 静默返回已有记录会让「两件不同的事只投递一次」，必须立刻暴露。
             */
            throw new BusinessException(ErrorCode::RESOURCE_CONFLICT, '相同幂等键对应的事件载荷不同。');
        }

        // 已入队（可能是待执行、执行中或已完成）：不改变状态。失败终态要重新投递走 replay()，
        // 不做「再次发布即复活」—— 那会让一个必然失败的事件被上游反复喂进队列。
        return new OutboxPublishResultDTO((int) $existing->id, false);
    }

    /**
     * 单条事件的最大尝试次数：按事件类型可覆盖全局值，写进入库快照。
     *
     * 快照下来是刻意的：调小全局配置不应该让在途事件立刻进死信。
     */
    private function maxAttempts(string $eventType): int
    {
        $overrides = $this->config->get('outbox.event_max_attempts', []);
        $override = is_array($overrides) ? ($overrides[$eventType] ?? null) : null;

        return max(1, (int) ($override ?? $this->config->get('outbox.max_attempts', 8)));
    }

    /**
     * 键名递归排序后的紧凑 JSON。
     *
     * 摘要要能表达「同一份载荷」，所以必须先规范化：否则同一份数据换个键顺序
     * 就会算出不同摘要，重复发布的判断随之失效。
     *
     * @param array<array-key, mixed> $payload
     */
    private static function canonicalJson(array $payload): string
    {
        self::sortKeys($payload);

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<array-key, mixed> $value */
    private static function sortKeys(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                self::sortKeys($item);
            }
        }
        unset($item);

        // 只排序映射；列表的元素顺序是数据本身的一部分，重排会改变载荷含义。
        if (! array_is_list($value)) {
            ksort($value);
        }
    }
}
