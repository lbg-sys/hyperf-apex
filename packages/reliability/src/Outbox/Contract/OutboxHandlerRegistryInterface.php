<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbox\Contract;

interface OutboxHandlerRegistryInterface
{
    /**
     * 按事件类型取 handler，未注册返回 null。
     *
     * 未注册不作为异常抛出：那是投递层的判断（见 `OutboxDeliveryService`），
     * 而「配置里注册了两个同类型 handler」这种真正的配置错误在这里直接报错。
     */
    public function find(string $eventType): ?OutboxHandlerInterface;
}
