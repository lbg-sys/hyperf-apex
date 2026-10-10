<?php

declare(strict_types=1);

namespace Hyperf\Apex\Idempotency;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Idempotency\DTO\IdempotencyClaimDTO;
use Hyperf\Apex\Idempotency\Enum\IdempotencyStatus;
use Hyperf\Apex\Idempotency\Repository\Contract\IdempotencyRecordRepositoryInterface;
use Hyperf\Apex\Support\Time;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\Exception\UniqueConstraintViolationException;
use LogicException;
use RuntimeException;

final class IdempotencyService
{
    public function __construct(
        private readonly IdempotencyRecordRepositoryInterface $repository,
        private readonly ConfigInterface $config,
    ) {
    }

    public function claim(
        string $callerHash,
        string $endpoint,
        string $requestId,
        string $requestHash,
    ): IdempotencyClaimDTO {
        $nowTimestamp = time();
        $now = date('Y-m-d H:i:s', $nowTimestamp);
        $expireTime = date('Y-m-d H:i:s', $nowTimestamp + $this->retentionSeconds());

        try {
            $lockToken = $this->newLockToken();

            return IdempotencyClaimDTO::acquired($this->repository->create([
                'caller_hash' => $callerHash,
                'endpoint' => $endpoint,
                'request_id' => $requestId,
                'request_hash' => $requestHash,
                'status' => IdempotencyStatus::PROCESSING->value,
                'lock_token' => $lockToken,
                'expire_time' => $expireTime,
            ]), $lockToken);
        } catch (UniqueConstraintViolationException) {
            // 唯一键冲突是正常并发路径，以下状态判断决定重放、拒绝或接管超时记录。
        }

        $record = $this->repository->find($callerHash, $endpoint, $requestId);
        if ($record === null) {
            throw new RuntimeException('幂等记录唯一键冲突后无法读取记录。');
        }

        $staleBefore = date('Y-m-d H:i:s', $nowTimestamp - $this->processingTimeoutSeconds());
        if ($record->expire_time <= $now
            || ($record->status === IdempotencyStatus::PROCESSING->value && $record->update_time <= $staleBefore)) {
            // 接管即换令牌：旧执行者手上的令牌就此失效，它之后写回结果会被 fencing 拦下。
            $lockToken = $this->newLockToken();
            if ($this->repository->restart($record->id, $requestHash, $lockToken, $expireTime, $now, $staleBefore)) {
                $record->request_hash = $requestHash;
                $record->status = IdempotencyStatus::PROCESSING->value;
                $record->lock_token = $lockToken;
                $record->response_status = null;
                $record->response_content_type = null;
                $record->response_body = null;

                return IdempotencyClaimDTO::acquired($record, $lockToken);
            }

            $record = $this->repository->find($callerHash, $endpoint, $requestId)
                ?? throw new RuntimeException('幂等记录并发更新后无法读取记录。');
        }

        if (! hash_equals($record->request_hash, $requestHash)) {
            throw new BusinessException(ErrorCode::RESOURCE_CONFLICT, '相同幂等请求 ID 对应的请求载荷不同。');
        }

        if ($record->status === IdempotencyStatus::PROCESSING->value) {
            throw new BusinessException(ErrorCode::RESOURCE_CONFLICT, '相同幂等请求正在处理中，请稍后重试。');
        }

        if ($record->response_status === null || $record->response_body === null) {
            throw new RuntimeException('已完成的幂等记录缺少响应结果。');
        }

        return IdempotencyClaimDTO::replay($record);
    }

    /**
     * 写回执行结果，返回本次认领的结果是否落库。
     *
     * 返回 false 不是错误，而是「本次认领已被接管」：接管者才是当前持有者，
     * 它的结果才是权威结果。调用方据此记一条 warning 即可 —— 业务副作用已经发生，
     * 把已经执行完的请求变成 500 只会掩盖真正的问题。
     *
     * @throws LogicException 传入未持有认领令牌的声明（重放或构造错误）
     */
    public function complete(
        IdempotencyClaimDTO $claim,
        IdempotencyStatus $status,
        int $httpStatus,
        string $contentType,
        string $body,
    ): bool {
        if (! $claim->acquired || $claim->lockToken === null) {
            throw new LogicException('只有本次认领成功的幂等声明才能写回执行结果。');
        }

        return $this->repository->complete(
            $claim->record->id,
            $claim->lockToken,
            $status,
            $httpStatus,
            $contentType,
            $body,
        );
    }

    public function prune(): int
    {
        $batchSize = max(1, (int) $this->config->get('idempotency.cleanup_batch_size', 1000));
        $maxBatches = max(1, (int) $this->config->get('idempotency.cleanup_max_batches', 1000));
        $deleted = 0;
        $now = Time::now()->toDateTimeString();

        for ($batch = 0; $batch < $maxBatches; ++$batch) {
            $count = $this->repository->deleteExpired($now, $batchSize);
            $deleted += $count;
            if ($count < $batchSize) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * 生成一次性认领令牌。
     *
     * 用 random_bytes 而不是自增版本号：令牌参与写回条件，可猜的令牌等于把 fencing 交给运气。
     */
    private function newLockToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function retentionSeconds(): int
    {
        return max(60, (int) $this->config->get('idempotency.retention_seconds', 604800));
    }

    private function processingTimeoutSeconds(): int
    {
        return max(30, (int) $this->config->get('idempotency.processing_timeout_seconds', 300));
    }
}
