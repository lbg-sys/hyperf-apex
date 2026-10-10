<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbox\DTO;

use Hyperf\Apex\Outbox\Enum\OutboxHandleOutcome;

final readonly class OutboxHandleResultDTO
{
    public function __construct(
        public OutboxHandleOutcome $outcome,
        public ?string $message = null,
        public ?int $retryAfterSeconds = null,
        public ?string $resultSummary = null,
    ) {
    }

    public static function success(?string $summary = null): self
    {
        return new self(OutboxHandleOutcome::SUCCESS, resultSummary: $summary);
    }

    public static function retryable(string $message, ?int $retryAfterSeconds = null, ?string $summary = null): self
    {
        return new self(OutboxHandleOutcome::RETRYABLE, $message, $retryAfterSeconds, $summary);
    }

    public static function permanent(string $message, ?string $summary = null): self
    {
        return new self(OutboxHandleOutcome::PERMANENT, $message, resultSummary: $summary);
    }
}
