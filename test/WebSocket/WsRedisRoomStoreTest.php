<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket;

use Hyperf\Apex\Test\WebSocket\Support\WsTestConfig;
use Hyperf\Apex\WebSocket\Registry\WsRedisRoomStore;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 房间索引（Redis 驱动）的编排契约。
 *
 * Lua 本身不在单测里执行（那需要真实 Redis 或 Lua 解释器，交给集成环境），这里断言的是
 * PHP 侧的编排：键与参数的组装、numkeys 的声明、evalSha 缓存丢失后的 eval 回落、
 * 返回值到布尔/整数的映射，以及**失败不降级** —— 房间成员读不到就静默当成「没有成员」，
 * 会让推送发给错的集合，比抛错更危险（与限流的 fail-open 取舍相反）。
 *
 * 替身沿用仓库既有写法（见 test/RateLimit）：继承真实 Redis 类、跳过父构造，用 __call 拦截。
 */
final class WsRedisRoomStoreTest extends TestCase
{
    public function testJoinDeclaresBothKeysAndPassesCapacityAsFirstArgument(): void
    {
        $redis = new FakeRedisProxy();
        $redis->handlers[] = static fn (): int => 1;

        $this->assertTrue($this->store($redis, ['room_member_capacity' => 4])->joinRoom('ws:1', 'room:1'));

        [$name, $arguments] = $redis->calls[0];
        $this->assertSame('evalSha', $name);
        $this->assertSame(40, strlen((string) $arguments[0]), 'evalSha 的第一个参数是脚本 SHA');

        /** @var list<string> $payload */
        $payload = $arguments[1];
        $this->assertSame(['core:wsroom:room:room:1', 'core:wsroom:rooms'], array_slice($payload, 0, 2));
        // ARGV：容量、连接 id、房间名（房间名要进房间名索引集合）
        $this->assertSame(['4', 'ws:1', 'room:1'], array_slice($payload, 2));
        $this->assertSame(2, $arguments[2]);
    }

    public function testJoinReportsRoomCapacityExhaustion(): void
    {
        $redis = new FakeRedisProxy();
        $redis->handlers[] = static fn (): int => 0;

        $this->assertFalse($this->store($redis)->joinRoom('ws:1', 'room:1'));
    }

    public function testLeaveTargetsTheSameTwoKeys(): void
    {
        $redis = new FakeRedisProxy();
        $redis->handlers[] = static fn (): int => 1;

        $this->store($redis)->leaveRoom('ws:1', 'room:1');

        [$name, $arguments] = $redis->calls[0];
        $this->assertSame('evalSha', $name);

        /** @var list<string> $payload */
        $payload = $arguments[1];
        $this->assertSame(['core:wsroom:room:room:1', 'core:wsroom:rooms'], array_slice($payload, 0, 2));
        // ARGV：连接 id、房间名（最后一个成员离开时要把房间从索引里摘掉）
        $this->assertSame(['ws:1', 'room:1'], array_slice($payload, 2));
    }

    public function testScriptCacheMissFallsBackToEval(): void
    {
        // phpredis 的 evalSha 在脚本缓存丢失（Redis 重启、SCRIPT FLUSH）时不会自动回落。
        $redis = new FakeRedisProxy();
        $redis->handlers[] = static fn () => throw new RuntimeException('NOSCRIPT No matching script.');
        $redis->handlers[] = static fn (): int => 1;

        $this->assertTrue($this->store($redis)->joinRoom('ws:1', 'room:1'));
        $this->assertSame(['evalSha', 'eval'], array_column($redis->calls, 0));
    }

    public function testRedisFailureIsNotSwallowedIntoAnEmptyRoom(): void
    {
        $redis = new FakeRedisProxy();
        $redis->handlers[] = static fn () => throw new RuntimeException('NOSCRIPT No matching script.');
        $redis->handlers[] = static fn () => throw new RuntimeException('连接失败');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('房间索引访问 Redis 失败');

        $this->store($redis)->joinRoom('ws:1', 'room:1');
    }

    public function testConnectionsByRoomReadsTheMemberSet(): void
    {
        $redis = new FakeRedisProxy();
        $redis->handlers[] = static fn (): array => ['ws:2', '', 'ws:1'];

        $this->assertSame(['ws:2', 'ws:1'], $this->store($redis)->connectionsByRoom('room:1'));
        $this->assertSame(['sMembers', ['core:wsroom:room:room:1']], $redis->calls[0]);
    }

    public function testConnectionsByRoomToleratesANonArrayAnswer(): void
    {
        $redis = new FakeRedisProxy();
        $redis->handlers[] = static fn (): bool => false;

        $this->assertSame([], $this->store($redis)->connectionsByRoom('room:1'));
    }

    public function testCountRoomsReadsTheIndexSetCardinality(): void
    {
        $redis = new FakeRedisProxy();
        $redis->handlers[] = static fn (): int => 5;

        $this->assertSame(5, $this->store($redis)->countRooms());
        $this->assertSame(['sCard', ['core:wsroom:rooms']], $redis->calls[0]);
    }

    public function testCountRoomsToleratesANonNumericAnswer(): void
    {
        $redis = new FakeRedisProxy();
        $redis->handlers[] = static fn (): bool => false;

        $this->assertSame(0, $this->store($redis)->countRooms());
    }

    public function testPoolAndPrefixComeFromConfiguration(): void
    {
        $redis = new FakeRedisProxy();
        $redis->handlers[] = static fn (): array => [];
        $factory = new FakeRedisFactory($redis);

        $store = new WsRedisRoomStore($factory, WsTestConfig::make([
            'room_store_redis_pool' => 'ws',
            'room_store_prefix' => 'custom:',
        ]));
        $store->connectionsByRoom('room:1');

        $this->assertSame('ws', $factory->requestedPool);
        $this->assertSame([['sMembers', ['custom:room:room:1']]], $redis->calls);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function store(FakeRedisProxy $redis, array $overrides = []): WsRedisRoomStore
    {
        return new WsRedisRoomStore(new FakeRedisFactory($redis), WsTestConfig::make(array_replace([
            'room_member_capacity' => 4,
            'room_store_redis_pool' => 'default',
            'room_store_prefix' => 'core:wsroom:',
        ], $overrides)));
    }
}

/** 记录调用并按用例排队返回值的 Redis 代理替身。 */
final class FakeRedisProxy extends RedisProxy
{
    /** @var list<callable> */
    public array $handlers = [];

    /** @var list<array{0: string, 1: array<mixed>}> */
    public array $calls = [];

    public function __construct()
    {
    }

    public function __call(string $name, array $arguments): mixed
    {
        $this->calls[] = [$name, $arguments];

        $handler = array_shift($this->handlers);
        if ($handler === null) {
            throw new RuntimeException(sprintf('替身没有为 %s 准备返回值。', $name));
        }

        return $handler();
    }
}

/** 只回答「拿到哪个池」的工厂替身。 */
final class FakeRedisFactory extends RedisFactory
{
    public string $requestedPool = '';

    public function __construct(private readonly RedisProxy $proxy)
    {
    }

    public function get(string $poolName): RedisProxy
    {
        $this->requestedPool = $poolName;

        return $this->proxy;
    }
}
