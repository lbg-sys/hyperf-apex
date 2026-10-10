<?php

declare(strict_types=1);

namespace Hyperf\Apex\Idempotency\Repository;

use Hyperf\Apex\Database\Repository\AbstractRepository;
use Hyperf\Apex\Idempotency\Enum\IdempotencyStatus;
use Hyperf\Apex\Idempotency\Model\IdempotencyRecord;
use Hyperf\Apex\Idempotency\Repository\Contract\IdempotencyRecordRepositoryInterface;

/** @extends AbstractRepository<IdempotencyRecord> */
final class IdempotencyRecordRepository extends AbstractRepository implements IdempotencyRecordRepositoryInterface
{
    protected string $modelClass = IdempotencyRecord::class;

    public function create(array $data): IdempotencyRecord
    {
        return $this->newModel()->create($data);
    }

    public function find(string $callerHash, string $endpoint, string $requestId): ?IdempotencyRecord
    {
        return $this->query()
            ->where('caller_hash', $callerHash)
            ->where('endpoint', $endpoint)
            ->where('request_id', $requestId)
            ->first();
    }

    public function restart(
        int $id,
        string $requestHash,
        string $lockToken,
        string $expireTime,
        string $now,
        string $staleBefore,
    ): bool {
        $updated = $this->query()
            ->where('id', $id)
            ->where(static function ($query) use ($now, $staleBefore): void {
                $query->where('expire_time', '<=', $now)
                    ->orWhere(static function ($query) use ($staleBefore): void {
                        $query->where('status', IdempotencyStatus::PROCESSING->value)
                            ->where('update_time', '<=', $staleBefore);
                    });
            })
            ->update([
                'request_hash' => $requestHash,
                'status' => IdempotencyStatus::PROCESSING->value,
                'lock_token' => $lockToken,
                'response_status' => null,
                'response_content_type' => null,
                'response_body' => null,
                'expire_time' => $expireTime,
                'update_time' => $now,
            ]);

        return $updated === 1;
    }

    public function complete(
        int $id,
        string $lockToken,
        IdempotencyStatus $status,
        int $responseStatus,
        string $responseContentType,
        string $responseBody,
    ): bool {
        $updated = $this->query()
            ->where('id', $id)
            ->where('status', IdempotencyStatus::PROCESSING->value)
            // 令牌相等即 fencing：被接管过的旧执行者在这里写不进去，
            // 防止它用陈旧结果覆盖接管者的结果，或把接管者的 processing 提前置成终态。
            ->where('lock_token', $lockToken)
            ->update([
                'status' => $status->value,
                'response_status' => $responseStatus,
                'response_content_type' => $responseContentType,
                'response_body' => $responseBody,
            ]);

        return $updated === 1;
    }

    public function deleteExpired(string $now, int $limit): int
    {
        return $this->query()->where('expire_time', '<=', $now)->limit($limit)->delete();
    }
}
