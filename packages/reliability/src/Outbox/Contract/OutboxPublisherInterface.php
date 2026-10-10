<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbox\Contract;

use Hyperf\Apex\Outbox\DTO\OutboxPublishResultDTO;

interface OutboxPublisherInterface
{
    public function publish(OutboxEventInterface $event): OutboxPublishResultDTO;
}
