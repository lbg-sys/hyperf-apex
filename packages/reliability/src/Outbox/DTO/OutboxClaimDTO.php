<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbox\DTO;

/**
 * 一次认领的结果。
 *
 * `lockToken` 是本次认领的 fencing 令牌，写回状态时必须以它为准 ——
 * 租约过期后被别的 worker 接管的事件，旧 worker 不能再改它的状态。
 *
 * 令牌单独放在这里、不放进 {@see OutboxMessageDTO}：那个对象会交给业务 handler，
 * 认领令牌属于投递机制内部的东西，不该出现在业务代码能拿到的地方。
 */
final readonly class OutboxClaimDTO
{
    public function __construct(
        public OutboxMessageDTO $message,
        public string $lockToken,
    ) {
    }
}
