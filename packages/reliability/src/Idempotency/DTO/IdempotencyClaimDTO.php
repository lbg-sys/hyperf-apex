<?php

declare(strict_types=1);

namespace Hyperf\Apex\Idempotency\DTO;

use Hyperf\Apex\Idempotency\Model\IdempotencyRecord;

/**
 * 一次幂等声明的结果。
 *
 * `lockToken` 是本次认领的 fencing 令牌：写回结果时必须以它为准，
 * 只有当前持有者能落库。重放路径没有令牌 —— 重放不执行也不会写回。
 */
final readonly class IdempotencyClaimDTO
{
    private function __construct(
        public bool $acquired,
        public IdempotencyRecord $record,
        public ?string $lockToken,
    ) {
    }

    public static function acquired(IdempotencyRecord $record, string $lockToken): self
    {
        return new self(true, $record, $lockToken);
    }

    public static function replay(IdempotencyRecord $record): self
    {
        return new self(false, $record, null);
    }
}
