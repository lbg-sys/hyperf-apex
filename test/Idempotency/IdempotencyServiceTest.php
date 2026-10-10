<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Idempotency;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Idempotency\Enum\IdempotencyStatus;
use Hyperf\Apex\Idempotency\IdempotencyService;
use Hyperf\Apex\Test\Idempotency\Support\InMemoryIdempotencyRecordRepository;
use Hyperf\Config\Config;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 幂等编排层的判据（fencing 之外的完整 claim 语义）。
 *
 * `claim()` 是同一条记录在并发下会走出多条路径的地方：首次创建、重放、载荷冲突、
 * 处理中冲突、接管成功、接管竞态落败、坏数据兜底。每条路径都对应一个真实故障形态，
 * 逐条钉住是为了让「为什么这个请求被拒 / 为什么重复了」能在代码里找到答案。
 *
 * `prune()` 的批量语义也在这里：它同时受 batch_size 与 max_batches 两个上限约束。
 */
final class IdempotencyServiceTest extends TestCase
{
    public function testFirstClaimAcquiresTheRecord(): void
    {
        $claim = $this->service()->claim('caller', 'POST /api/order/create', 'req-1', 'hash');

        $this->assertTrue($claim->acquired);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $claim->lockToken);
        $this->assertSame(IdempotencyStatus::PROCESSING->value, $claim->record->status);
        $this->assertSame('req-1', $claim->record->request_id);
    }

    public function testReplayReturnsTheStoredResponse(): void
    {
        $service = $this->service();
        $claim = $service->claim('caller', 'POST /api/order/create', 'req-1', 'hash');
        $service->complete($claim, IdempotencyStatus::SUCCEEDED, 200, 'application/json', '{"ok":true}');

        $replay = $service->claim('caller', 'POST /api/order/create', 'req-1', 'hash');

        // 重放不执行、不发令牌，但把上次的结果原样交给调用方。
        $this->assertFalse($replay->acquired);
        $this->assertNull($replay->lockToken);
        $this->assertSame('{"ok":true}', $replay->record->response_body);
        $this->assertSame(200, $replay->record->response_status);
        $this->assertSame(IdempotencyStatus::SUCCEEDED->value, $replay->record->status);
    }

    public function testSameRequestIdWithDifferentPayloadIsRejected(): void
    {
        $service = $this->service();
        $claim = $service->claim('caller', 'POST /api/order/create', 'req-1', 'hash-a');
        $service->complete($claim, IdempotencyStatus::SUCCEEDED, 200, 'application/json', '{}');

        try {
            $service->claim('caller', 'POST /api/order/create', 'req-1', 'hash-b');
            $this->fail('相同请求 ID 但载荷不同，应当拒绝而不是当作重放。');
        } catch (BusinessException $exception) {
            $this->assertSame(ErrorCode::RESOURCE_CONFLICT->value, $exception->getErrorCode());
            $this->assertStringContainsString('请求载荷不同', $exception->getMessage());
        }
    }

    public function testProcessingRecordWithoutTimeoutIsRejected(): void
    {
        $service = $this->service();
        $service->claim('caller', 'POST /api/order/create', 'req-1', 'hash');

        try {
            $service->claim('caller', 'POST /api/order/create', 'req-1', 'hash');
            $this->fail('记录还在处理中且未超时，应当让调用方稍后重试。');
        } catch (BusinessException $exception) {
            $this->assertSame(ErrorCode::RESOURCE_CONFLICT->value, $exception->getErrorCode());
            $this->assertStringContainsString('正在处理中', $exception->getMessage());
        }
    }

    public function testLosingTheTakeoverRaceYieldsProcessingConflict(): void
    {
        $repository = new InMemoryIdempotencyRecordRepository();
        $service = new IdempotencyService($repository, $this->config());

        $first = $service->claim('caller', 'POST /api/order/create', 'req-1', 'hash');
        $repository->expire($first->record->id);

        // 记录已过期、本应接管，但 restart 的 CAS 落败（另一个进程抢先接管）。
        $repository->failRestart = true;

        try {
            $service->claim('caller', 'POST /api/order/create', 'req-1', 'hash');
            $this->fail('接管落败后应当看到「处理中」，而不是自认为接管成功。');
        } catch (BusinessException $exception) {
            $this->assertStringContainsString('正在处理中', $exception->getMessage());
        }

        // 落败方没拿到令牌：原令牌保持不变（接管方才是持有者）。
        $this->assertSame($first->lockToken, $repository->find('caller', 'POST /api/order/create', 'req-1')?->lock_token);
    }

    public function testCompletedRecordWithMissingResponseIsRejected(): void
    {
        $repository = new InMemoryIdempotencyRecordRepository();
        $service = new IdempotencyService($repository, $this->config());

        $claim = $service->claim('caller', 'POST /api/order/create', 'req-1', 'hash');
        $service->complete($claim, IdempotencyStatus::SUCCEEDED, 200, 'application/json', '{}');
        // 数据被部分写入：状态终态但响应体丢了。
        $repository->corruptResponse($claim->record->id);

        // 与其把残缺的结果当作重放回给调用方，不如报错 —— 静默回 null 更难排查。
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('已完成的幂等记录缺少响应结果');

        $service->claim('caller', 'POST /api/order/create', 'req-1', 'hash');
    }

    public function testPruneBatchesAndStopsWhenShorterThanBatchSize(): void
    {
        $repository = new InMemoryIdempotencyRecordRepository();
        $repository->deleteExpiredQueue = [2, 2, 1];
        $service = new IdempotencyService($repository, $this->config([
            'cleanup_batch_size' => 2,
            'cleanup_max_batches' => 10,
        ]));

        // 2 + 2 + 1 = 5；第三批少于 batch_size，说明没有更多过期记录了，停止。
        $this->assertSame(5, $service->prune());
        $this->assertSame(3, $repository->deleteExpiredCalls);
    }

    public function testPruneRespectsMaxBatches(): void
    {
        $repository = new InMemoryIdempotencyRecordRepository();
        // 每一批都「满」：只有 max_batches 能拦住它，否则会一直删下去。
        $repository->deleteExpiredQueue = [1, 1, 1, 1];
        $service = new IdempotencyService($repository, $this->config([
            'cleanup_batch_size' => 1,
            'cleanup_max_batches' => 2,
        ]));

        $this->assertSame(2, $service->prune());
        $this->assertSame(2, $repository->deleteExpiredCalls);
    }

    private function service(): IdempotencyService
    {
        return new IdempotencyService(new InMemoryIdempotencyRecordRepository(), $this->config());
    }

    /** @param array<string, int> $overrides */
    private function config(array $overrides = []): Config
    {
        return new Config([
            'idempotency' => array_merge([
                'retention_seconds' => 604800,
                'processing_timeout_seconds' => 300,
                'cleanup_batch_size' => 1000,
                'cleanup_max_batches' => 1000,
            ], $overrides),
        ]);
    }
}
