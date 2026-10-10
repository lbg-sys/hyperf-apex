<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Outbox\Support;

use Hyperf\Apex\Outbox\Contract\OutboxHandlerInterface;
use Hyperf\Apex\Outbox\Contract\OutboxHandlerRegistryInterface;

final class FakeOutboxHandlerRegistry implements OutboxHandlerRegistryInterface
{
    /** @param list<OutboxHandlerInterface> $handlers */
    public function __construct(private readonly array $handlers = [])
    {
    }

    public function find(string $eventType): ?OutboxHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->eventType() === $eventType) {
                return $handler;
            }
        }

        return null;
    }
}
