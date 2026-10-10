<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbox\DTO;

final readonly class OutboxMessageDTO
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $id,
        public string $eventType,
        public string $scopeKey,
        public string $idempotencyKey,
        public ?string $aggregateType,
        public ?string $aggregateId,
        public array $payload,
        public int $attemptCount,
        public int $maxAttempts,
    ) {
    }
}
