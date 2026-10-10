<?php

declare(strict_types=1);

namespace Hyperf\Apex\Reliability;

use Hyperf\Apex\Idempotency\Repository\Contract\IdempotencyRecordRepositoryInterface;
use Hyperf\Apex\Idempotency\Repository\IdempotencyRecordRepository;
use Hyperf\Apex\Notify\Contract\NotifyChannelRegistryInterface;
use Hyperf\Apex\Notify\Registry\NotifyChannelRegistry;
use Hyperf\Apex\Outbox\Command\OutboxPruneCommand;
use Hyperf\Apex\Outbox\Contract\OutboxHandlerRegistryInterface;
use Hyperf\Apex\Outbox\Contract\OutboxPublisherInterface;
use Hyperf\Apex\Outbox\OutboxPublisher;
use Hyperf\Apex\Outbox\Registry\OutboxHandlerRegistry;
use Hyperf\Apex\Outbox\Repository\Contract\OutboxEventRepositoryInterface;
use Hyperf\Apex\Outbox\Repository\OutboxEventRepository;

class ConfigProvider
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                IdempotencyRecordRepositoryInterface::class => IdempotencyRecordRepository::class,
                NotifyChannelRegistryInterface::class => NotifyChannelRegistry::class,
                OutboxEventRepositoryInterface::class => OutboxEventRepository::class,
                OutboxHandlerRegistryInterface::class => OutboxHandlerRegistry::class,
                OutboxPublisherInterface::class => OutboxPublisher::class,
            ],
            'commands' => [
                OutboxPruneCommand::class,
            ],
            'listeners' => [],
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The configuration files for apex reliability suite.',
                    'source' => __DIR__ . '/../publish/config/',
                    'destination' => BASE_PATH . '/config/autoload/',
                ],
                [
                    'id' => 'migrations',
                    'description' => 'The database migrations for apex reliability suite.',
                    'source' => __DIR__ . '/../publish/migrations/',
                    'destination' => BASE_PATH . '/migrations/',
                ],
            ],
        ];
    }
}
