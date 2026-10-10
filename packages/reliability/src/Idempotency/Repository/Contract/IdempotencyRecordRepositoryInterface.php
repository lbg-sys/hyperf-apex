<?php

declare(strict_types=1);

namespace Hyperf\Apex\Idempotency\Repository\Contract;

use Hyperf\Apex\Idempotency\Enum\IdempotencyStatus;
use Hyperf\Apex\Idempotency\Model\IdempotencyRecord;

interface IdempotencyRecordRepositoryInterface
{
    /** @param array<string, mixed> $data */
    public function create(array $data): IdempotencyRecord;

    public function find(string $callerHash, string $endpoint, string $requestId): ?IdempotencyRecord;

    public function restart(
        int $id,
        string $requestHash,
        string $lockToken,
        string $expireTime,
        string $now,
        string $staleBefore,
    ): bool;

    /**
     * 写回执行结果。`lockToken` 必须匹配当前令牌，否则视为已被接管，不做任何写入。
     */
    public function complete(
        int $id,
        string $lockToken,
        IdempotencyStatus $status,
        int $responseStatus,
        string $responseContentType,
        string $responseBody,
    ): bool;

    public function deleteExpired(string $now, int $limit): int;
}
