<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbox\DTO;

final readonly class OutboxPublishResultDTO
{
    public function __construct(
        public int $id,
        public bool $created,
    ) {
    }
}
