<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Outbox\Support;

use Hyperf\Apex\Outbox\Contract\OutboxHandlerInterface;
use Hyperf\Apex\Outbox\DTO\OutboxHandleResultDTO;
use Hyperf\Apex\Outbox\DTO\OutboxMessageDTO;
use Throwable;

final class FakeOutboxHandler implements OutboxHandlerInterface
{
    public int $calls = 0;

    /** @var list<OutboxMessageDTO> */
    public array $messages = [];

    public function __construct(
        private readonly string $eventType,
        private readonly ?OutboxHandleResultDTO $result = null,
        private readonly ?Throwable $throwable = null,
    ) {
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    public function handle(OutboxMessageDTO $message): OutboxHandleResultDTO
    {
        ++$this->calls;
        $this->messages[] = $message;

        if ($this->throwable !== null) {
            throw $this->throwable;
        }

        return $this->result ?? OutboxHandleResultDTO::success();
    }
}
