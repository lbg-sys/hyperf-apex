<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Outbox\Support;

use Hyperf\Apex\Outbox\Contract\OutboxEventInterface;

final readonly class FakeOutboxEvent implements OutboxEventInterface
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        private string $eventType,
        private string $scopeKey,
        private string $idempotencyKey,
        private array $payload,
        private ?string $aggregateType = null,
        private ?string $aggregateId = null,
    ) {
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    public function scopeKey(): string
    {
        return $this->scopeKey;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function aggregateType(): ?string
    {
        return $this->aggregateType;
    }

    public function aggregateId(): ?string
    {
        return $this->aggregateId;
    }

    public function payload(): array
    {
        return $this->payload;
    }
}
