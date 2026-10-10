<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbox\Contract;

use Hyperf\Apex\Outbox\DTO\OutboxHandleResultDTO;
use Hyperf\Apex\Outbox\DTO\OutboxMessageDTO;

interface OutboxHandlerInterface
{
    public function eventType(): string;

    public function handle(OutboxMessageDTO $message): OutboxHandleResultDTO;
}
