<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Outbox;

use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Outbox\Enum\OutboxEventStatus;
use Hyperf\Apex\Outbox\OutboxPublisher;
use Hyperf\Apex\Test\Outbox\Support\FakeOutboxEvent;
use Hyperf\Apex\Test\Outbox\Support\FakeOutboxEventRepository;
use Hyperf\Config\Config;
use PHPUnit\Framework\TestCase;

final class OutboxPublisherTest extends TestCase
{
    public function testPublishesPendingEventWithAttemptsSnapshot(): void
    {
        $repository = new FakeOutboxEventRepository();
        $result = $this->publisher($repository)->publish(
            new FakeOutboxEvent('demo.created', 'scope:1', 'record-1', ['recordId' => 1])
        );

        $this->assertTrue($result->created);

        $row = $repository->raw($result->id);
        $this->assertNotNull($row);
        $this->assertSame(OutboxEventStatus::PENDING->value, $row['status']);
        $this->assertSame('demo.created', $row['event_type']);
        $this->assertSame('scope:1', $row['scope_key']);
        $this->assertSame(8, $row['max_attempts']);
        $this->assertSame(0, $row['attempt_count']);
        $this->assertSame(
            hash('sha256', json_encode(['recordId' => 1], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            $row['payload_hash'],
        );
    }

    public function testSameEventIsNotQueuedTwiceEvenWhenKeyOrderDiffers(): void
    {
        $repository = new FakeOutboxEventRepository();
        $publisher = $this->publisher($repository);

        $first = $publisher->publish(new FakeOutboxEvent('demo.created', 'scope:1', 'record-1', [
            'amount' => 100,
            'buyer' => ['id' => 7, 'name' => '甲'],
        ]));
        $second = $publisher->publish(new FakeOutboxEvent('demo.created', 'scope:1', 'record-1', [
            'buyer' => ['name' => '甲', 'id' => 7],
            'amount' => 100,
        ]));

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $repository->count());
    }

    public function testSameIdempotencyKeyWithDifferentPayloadIsRejected(): void
    {
        $repository = new FakeOutboxEventRepository();
        $publisher = $this->publisher($repository);

        $publisher->publish(new FakeOutboxEvent('demo.created', 'scope:1', 'record-1', ['amount' => 100]));

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('相同幂等键对应的事件载荷不同。');

        $publisher->publish(new FakeOutboxEvent('demo.created', 'scope:1', 'record-1', ['amount' => 999]));
    }

    public function testEventTypeMaxAttemptsOverrideWins(): void
    {
        $repository = new FakeOutboxEventRepository();
        $publisher = $this->publisher($repository, ['demo.created' => 3]);

        $overridden = $publisher->publish(new FakeOutboxEvent('demo.created', 'scope:1', 'record-1', ['a' => 1]));
        $fallback = $publisher->publish(new FakeOutboxEvent('demo.updated', 'scope:1', 'record-1', ['a' => 1]));

        $this->assertSame(3, $repository->raw($overridden->id)['max_attempts']);
        $this->assertSame(8, $repository->raw($fallback->id)['max_attempts']);
    }

    public function testSameKeyInAnotherScopeIsASeparateEvent(): void
    {
        $repository = new FakeOutboxEventRepository();
        $publisher = $this->publisher($repository);

        $scopeOne = $publisher->publish(new FakeOutboxEvent('demo.created', 'scope:1', 'record-1', ['a' => 1]));
        $scopeTwo = $publisher->publish(new FakeOutboxEvent('demo.created', 'scope:2', 'record-1', ['a' => 1]));

        $this->assertTrue($scopeOne->created);
        $this->assertTrue($scopeTwo->created);
        $this->assertNotSame($scopeOne->id, $scopeTwo->id);
        $this->assertSame(2, $repository->count());
    }

    /** @param array<string, int> $eventMaxAttempts */
    private function publisher(FakeOutboxEventRepository $repository, array $eventMaxAttempts = []): OutboxPublisher
    {
        return new OutboxPublisher($repository, new Config([
            'outbox' => [
                'max_attempts' => 8,
                'event_max_attempts' => $eventMaxAttempts,
            ],
        ]));
    }
}
