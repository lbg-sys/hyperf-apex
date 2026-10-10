<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbox\Contract;

interface OutboxEventInterface
{
    public function eventType(): string;

    public function scopeKey(): string;

    public function idempotencyKey(): string;

    public function aggregateType(): ?string;

    public function aggregateId(): ?string;

    /** @return array<string, mixed> */
    public function payload(): array;
}
