<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Idempotency;

use Hyperf\Apex\Idempotency\Enum\IdempotencyStatus;
use Hyperf\Apex\Idempotency\IdempotencyService;
use Hyperf\Apex\Test\Idempotency\Support\InMemoryIdempotencyRecordRepository;
use Hyperf\Config\Config;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * 幂等接管的 fencing：被接管过的旧执行者不能写回结果。
 *
 * 没有令牌时，接管只重置状态，旧执行者的写回条件（id + processing）依旧成立，
 * 它会用陈旧结果覆盖接管者、并把接管者的 processing 提前置成终态。
 */
final class IdempotencyFencingTest extends TestCase
{
    public function testSupersededClaimCannotWriteBack(): void
    {
        $repository = new InMemoryIdempotencyRecordRepository();
        $service = $this->service($repository);

        $first = $service->claim('caller', 'POST /api/order/create', 'req-1', 'payload-hash');
        $this->assertTrue($first->acquired);

        // 执行卡住、记录过期，同键请求接管同一条记录。
        $repository->expire($first->record->id);
        $second = $service->claim('caller', 'POST /api/order/create', 'req-1', 'payload-hash');
        $this->assertTrue($second->acquired);
        $this->assertSame($first->record->id, $second->record->id);
        $this->assertNotSame($first->lockToken, $second->lockToken);

        // 接管后状态仍是 processing：这里唯一能拦住 A 的就是令牌。
        $this->assertFalse($service->complete($first, IdempotencyStatus::SUCCEEDED, 200, 'application/json', '{"by":"A"}'));

        // B 才是当前持有者，它写回成功，且结果不会被 A 覆盖。
        $this->assertTrue($service->complete($second, IdempotencyStatus::SUCCEEDED, 200, 'application/json', '{"by":"B"}'));
        $this->assertSame('{"by":"B"}', $repository->responseBody($second->record->id));
    }

    public function testReplayedClaimCannotWriteBack(): void
    {
        $repository = new InMemoryIdempotencyRecordRepository();
        $service = $this->service($repository);

        $first = $service->claim('caller', 'POST /api/order/create', 'req-1', 'payload-hash');
        $this->assertTrue($service->complete($first, IdempotencyStatus::SUCCEEDED, 200, 'application/json', '{"ok":true}'));

        $replay = $service->claim('caller', 'POST /api/order/create', 'req-1', 'payload-hash');
        $this->assertFalse($replay->acquired);
        $this->assertNull($replay->lockToken);

        $this->expectException(LogicException::class);
        $service->complete($replay, IdempotencyStatus::SUCCEEDED, 200, 'application/json', '{"ok":true}');
    }

    private function service(InMemoryIdempotencyRecordRepository $repository): IdempotencyService
    {
        return new IdempotencyService($repository, new Config([
            'idempotency' => [
                'retention_seconds' => 604800,
                'processing_timeout_seconds' => 300,
            ],
        ]));
    }
}
