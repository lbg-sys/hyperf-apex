<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket;

use Hyperf\Apex\Test\WebSocket\Support\FakeWsRoomStore;
use Hyperf\Apex\Test\WebSocket\Support\WsTestConfig;
use Hyperf\Apex\WebSocket\Contract\WsRoomStoreInterface;
use Hyperf\Apex\WebSocket\Registry\WsRedisRoomStore;
use Hyperf\Apex\WebSocket\Registry\WsRoomStore;
use Hyperf\Apex\WebSocket\Registry\WsTableRoomStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * 房间索引驱动选择器：按 websocket.room_store_driver 委派，并在构造期就把配错挡下来。
 *
 * 「配错」有两种，都必须当场失败而不是静默走默认实现：驱动名不认识、选了 redis 却没装 hyperf/redis。
 * 后者在本仓库里装不出来（reliability 依赖 hyperf/redis），因此只断言到 assertDriver() 这一层。
 */
final class WsRoomStoreTest extends TestCase
{
    public function testDefaultsToTheTableDriver(): void
    {
        $table = new FakeWsRoomStore();
        $store = new WsRoomStore($this->container([WsTableRoomStore::class => $table]), WsTestConfig::make());

        $this->assertTrue($store->joinRoom('ws:1', 'room:1'));
        $store->leaveRoom('ws:1', 'room:1');
        $this->assertSame([], $store->connectionsByRoom('room:1'));
        $table->members['room:1'] = ['ws:9'];
        $this->assertSame(['ws:9'], $store->connectionsByRoom('room:1'));
        $table->roomCount = 3;
        $this->assertSame(3, $store->countRooms());

        $this->assertSame([['ws:1', 'room:1']], $table->joined);
        $this->assertSame([['ws:1', 'room:1']], $table->left);
    }

    public function testRedisDriverDelegatesToTheRedisStore(): void
    {
        $redis = new FakeWsRoomStore();
        $redis->roomCount = 7;
        $store = new WsRoomStore(
            $this->container([WsRedisRoomStore::class => $redis]),
            WsTestConfig::make(['room_store_driver' => WsRoomStore::DRIVER_REDIS]),
        );

        $this->assertSame(7, $store->countRooms());
    }

    public function testUnknownDriverFailsAtConstructionTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('room_store_driver 只能是 table 或 redis');

        new WsRoomStore($this->container([]), WsTestConfig::make(['room_store_driver' => 'memcached']));
    }

    public function testDriverIsResolvedOnceAndReused(): void
    {
        // 两个实现都不轻（一个要建共享内存表，一个要建连接池）：每次调用都重新 make 是纯浪费。
        $container = new RecordingContainer([WsTableRoomStore::class => new FakeWsRoomStore()]);
        $store = new WsRoomStore($container, WsTestConfig::make());

        $store->joinRoom('ws:1', 'room:1');
        $store->joinRoom('ws:1', 'room:2');
        $store->countRooms();

        $this->assertSame(1, $container->gets);
    }

    public function testAssertDriverRejectsUnknownNamesAndMissingRedisClient(): void
    {
        WsRoomStore::assertDriver(WsRoomStore::DRIVER_TABLE);
        WsRoomStore::assertDriver(WsRoomStore::DRIVER_REDIS);

        $this->expectException(InvalidArgumentException::class);
        WsRoomStore::assertDriver('');
    }

    public function testRedisAvailabilityFollowsTheInstalledPackages(): void
    {
        // hyperf/redis 是可选依赖（composer.json 的 suggest）：本仓库装着它，所以这里为 true。
        $this->assertTrue(WsRoomStore::redisAvailable());
    }

    /**
     * @param array<string, WsRoomStoreInterface> $map
     */
    private function container(array $map): RecordingContainer
    {
        return new RecordingContainer($map);
    }
}

/** 按类名取实例并记录次数的容器替身。 */
final class RecordingContainer implements ContainerInterface
{
    public int $gets = 0;

    /** @param array<string, WsRoomStoreInterface> $map */
    public function __construct(private readonly array $map)
    {
    }

    public function get(string $id): mixed
    {
        ++$this->gets;

        return $this->map[$id] ?? throw new RuntimeException(sprintf('容器里没有 %s。', $id));
    }

    public function has(string $id): bool
    {
        return isset($this->map[$id]);
    }
}
