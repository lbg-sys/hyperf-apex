<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Outbox;

use Carbon\CarbonImmutable;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Outbox\DTO\OutboxHandleResultDTO;
use Hyperf\Apex\Outbox\Enum\OutboxEventStatus;
use Hyperf\Apex\Outbox\Service\OutboxDeliveryService;
use Hyperf\Apex\Test\Outbox\Support\FakeOutboxEventRepository;
use Hyperf\Apex\Test\Outbox\Support\FakeOutboxHandler;
use Hyperf\Apex\Test\Outbox\Support\FakeOutboxHandlerRegistry;
use Hyperf\Apex\Test\Support\StubLoggerFactory;
use Hyperf\Config\Config;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 投递链路的行为契约：成功、退避、死信、失败终态、未注册 handler、异常、fencing、重放、清理。
 *
 * 时间用 CarbonImmutable::setTestNow() 固定，退避断言才能落在确定的秒上
 * （`Time::now()` 也读同一个测试时钟）。
 */
final class OutboxDeliveryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        CarbonImmutable::setTestNow('2026-10-09 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
    }

    public function testSuccessWritesSucceededAndReleasesLease(): void
    {
        $repository = new FakeOutboxEventRepository();
        $handler = new FakeOutboxHandler('order.paid', OutboxHandleResultDTO::success('已投递'));
        $service = $this->service($repository, [$handler]);
        $id = $this->seed($repository);

        $this->assertSame(1, $service->deliverBatch(10));

        $row = $repository->raw($id);
        $this->assertSame(OutboxEventStatus::SUCCEEDED->value, $row['status']);
        $this->assertSame('已投递', $row['result_summary']);
        $this->assertSame('2026-10-09 10:00:00', $row['success_time']);
        $this->assertNull($row['lock_token']);
        $this->assertSame(1, $handler->calls);
        // 认领即消耗一次尝试，handler 看到的就是这一次。
        $this->assertSame(1, $handler->messages[0]->attemptCount);
    }

    public function testRetryableSchedulesBackoff(): void
    {
        $repository = new FakeOutboxEventRepository();
        $handler = new FakeOutboxHandler('order.paid', OutboxHandleResultDTO::retryable('下游 502'));
        $service = $this->service($repository, [$handler]);
        $id = $this->seed($repository);

        $service->deliverBatch(10);

        $row = $repository->raw($id);
        $this->assertSame(OutboxEventStatus::PENDING->value, $row['status']);
        $this->assertSame('retryable', $row['last_failure_type']);
        $this->assertSame('下游 502', $row['last_error']);
        $this->assertSame(1, $row['attempt_count']);
        $this->assertNull($row['lock_token']);
        // 第一次重试 = backoff_base_seconds（抖动在用例里配成 0）。
        $this->assertSame('2026-10-09 10:00:30', $row['next_attempt_time']);
    }

    public function testRetryAfterFromHandlerOverridesBackoff(): void
    {
        $repository = new FakeOutboxEventRepository();
        $handler = new FakeOutboxHandler('order.paid', OutboxHandleResultDTO::retryable('稍后再试', 600));
        $service = $this->service($repository, [$handler]);
        $id = $this->seed($repository);

        $service->deliverBatch(10);

        $this->assertSame('2026-10-09 10:10:00', $repository->raw($id)['next_attempt_time']);
    }

    public function testBackoffIsCappedByMaxSeconds(): void
    {
        $repository = new FakeOutboxEventRepository();
        $handler = new FakeOutboxHandler('order.paid', OutboxHandleResultDTO::retryable('下游 502'));
        $service = $this->service($repository, [$handler]);
        $id = $this->seed($repository, maxAttempts: 10);
        // 认领后是第 8 次：30 * 2^7 = 3840 秒，被 backoff_max_seconds 夹到 1800。
        $repository->mutate($id, ['attempt_count' => 7]);

        $service->deliverBatch(10);

        $this->assertSame('2026-10-09 10:30:00', $repository->raw($id)['next_attempt_time']);
    }

    public function testRetryableAfterExhaustedAttemptsBecomesDeadLetter(): void
    {
        $repository = new FakeOutboxEventRepository();
        $handler = new FakeOutboxHandler('order.paid', OutboxHandleResultDTO::retryable('还是 502'));
        $service = $this->service($repository, [$handler]);
        $id = $this->seed($repository, maxAttempts: 1);

        $service->deliverBatch(10);

        $row = $repository->raw($id);
        $this->assertSame(OutboxEventStatus::DEAD_LETTER->value, $row['status']);
        $this->assertSame('retryable', $row['last_failure_type']);
        $this->assertSame('还是 502', $row['last_error']);
        $this->assertSame('2026-10-09 10:00:00', $row['fail_time']);
        $this->assertNull($row['next_attempt_time']);
    }

    public function testPermanentFailureMarksFailed(): void
    {
        $repository = new FakeOutboxEventRepository();
        $handler = new FakeOutboxHandler('order.paid', OutboxHandleResultDTO::permanent('订单不存在'));
        $service = $this->service($repository, [$handler]);
        $id = $this->seed($repository);

        $service->deliverBatch(10);

        $row = $repository->raw($id);
        $this->assertSame(OutboxEventStatus::FAILED->value, $row['status']);
        $this->assertSame('permanent', $row['last_failure_type']);
        $this->assertSame('订单不存在', $row['last_error']);
        $this->assertSame('2026-10-09 10:00:00', $row['fail_time']);
    }

    public function testUnregisteredEventTypeStaysRetryableWithExplicitError(): void
    {
        $repository = new FakeOutboxEventRepository();
        $service = $this->service($repository, []);
        $id = $this->seed($repository);

        $service->deliverBatch(10);

        $row = $repository->raw($id);
        $this->assertSame(OutboxEventStatus::PENDING->value, $row['status']);
        $this->assertStringContainsString('没有注册 handler', (string) $row['last_error']);
        $this->assertSame(1, $row['attempt_count']);
    }

    public function testHandlerExceptionIsRetryable(): void
    {
        $repository = new FakeOutboxEventRepository();
        $handler = new FakeOutboxHandler('order.paid', null, new RuntimeException('连接超时'));
        $service = $this->service($repository, [$handler]);
        $id = $this->seed($repository);

        $service->deliverBatch(10);

        $row = $repository->raw($id);
        $this->assertSame(OutboxEventStatus::PENDING->value, $row['status']);
        $this->assertSame('连接超时', $row['last_error']);
    }

    public function testSupersededWorkerCannotWriteBack(): void
    {
        $repository = new FakeOutboxEventRepository();
        $handler = new FakeOutboxHandler('order.paid');
        $service = $this->service($repository, [$handler]);
        $id = $this->seed($repository);

        $first = $repository->claimDue('2026-10-09 10:00:00', 10, 'token-A', '2026-10-09 10:05:00')[0];
        // 租约过期后被另一个 worker 接管。
        $repository->mutate($id, ['lock_expire_time' => '2026-10-09 09:59:00']);
        $second = $repository->claimDue('2026-10-09 10:00:00', 10, 'token-B', '2026-10-09 10:05:00')[0];

        $this->assertFalse($service->deliverOne($first), '令牌已被接管的旧 worker 不能写回结果。');
        $this->assertSame(OutboxEventStatus::PROCESSING->value, $repository->raw($id)['status']);

        $this->assertTrue($service->deliverOne($second));
        $this->assertSame(OutboxEventStatus::SUCCEEDED->value, $repository->raw($id)['status']);
    }

    public function testReplayOnlyAllowsStoppedFailures(): void
    {
        $repository = new FakeOutboxEventRepository();
        $service = $this->service($repository, []);
        $id = $this->seed($repository);

        try {
            $service->replay($id);
            $this->fail('待执行状态的事件不允许重放。');
        } catch (BusinessException $exception) {
            $this->assertStringContainsString('不允许重放', $exception->getMessage());
        }

        $repository->mutate($id, ['status' => OutboxEventStatus::DEAD_LETTER->value, 'attempt_count' => 3]);
        $service->replay($id);

        $row = $repository->raw($id);
        $this->assertSame(OutboxEventStatus::PENDING->value, $row['status']);
        $this->assertSame(0, $row['attempt_count']);
        $this->assertSame(1, $row['replay_count']);
        $this->assertSame('2026-10-09 10:00:00', $row['last_replay_time']);
    }

    public function testDisabledOutboxClaimsNothing(): void
    {
        $repository = new FakeOutboxEventRepository();
        $service = $this->service($repository, [], ['enabled' => false]);
        $id = $this->seed($repository);

        $this->assertSame(0, $service->deliverBatch(10));
        $this->assertSame(OutboxEventStatus::PENDING->value, $repository->raw($id)['status']);
    }

    public function testPruneDeletesOnlySucceededOutsideRetentionWindow(): void
    {
        $repository = new FakeOutboxEventRepository();
        $service = $this->service($repository, [], ['retention_days' => 7]);

        $stale = $this->seed($repository, key: 'stale');
        $fresh = $this->seed($repository, key: 'fresh');
        $failed = $this->seed($repository, key: 'failed');

        $repository->mutate($stale, ['status' => OutboxEventStatus::SUCCEEDED->value, 'success_time' => '2026-09-01 00:00:00']);
        $repository->mutate($fresh, ['status' => OutboxEventStatus::SUCCEEDED->value, 'success_time' => '2026-10-08 00:00:00']);
        $repository->mutate($failed, ['status' => OutboxEventStatus::FAILED->value, 'fail_time' => '2026-09-01 00:00:00']);

        $this->assertSame(1, $service->prune());
        $this->assertNull($repository->raw($stale));
        $this->assertNotNull($repository->raw($fresh));
        // 失败与死信永不自动删除：它们要人工看过再决定重放还是放弃。
        $this->assertNotNull($repository->raw($failed));
    }

    /**
     * @param list<FakeOutboxHandler> $handlers
     * @param array<string, mixed> $config
     */
    private function service(FakeOutboxEventRepository $repository, array $handlers = [], array $config = []): OutboxDeliveryService
    {
        return new OutboxDeliveryService(
            $repository,
            new FakeOutboxHandlerRegistry($handlers),
            new Config([
                'outbox' => array_merge([
                    'enabled' => true,
                    'max_attempts' => 8,
                    'lock_seconds' => 300,
                    'backoff_base_seconds' => 30,
                    'backoff_max_seconds' => 1800,
                    'backoff_jitter_ratio' => 0.0,
                    'error_max_length' => 4000,
                    'retention_days' => 0,
                ], $config),
            ]),
            StubLoggerFactory::create(),
        );
    }

    private function seed(FakeOutboxEventRepository $repository, string $key = 'order-1', int $maxAttempts = 8): int
    {
        $event = $repository->create([
            'scope_key' => 'scope:1',
            'event_type' => 'order.paid',
            'idempotency_key' => $key,
            'aggregate_type' => null,
            'aggregate_id' => null,
            'payload' => ['orderId' => 1],
            'payload_hash' => 'seed-hash',
            'status' => OutboxEventStatus::PENDING->value,
            'attempt_count' => 0,
            'max_attempts' => $maxAttempts,
        ]);

        return (int) $event->id;
    }
}
