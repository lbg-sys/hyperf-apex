<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Metrics;

use Hyperf\Apex\Metrics\Collector\GaugeSample;
use Hyperf\Apex\Metrics\Collector\WebSocketGaugeCollector;
use Hyperf\Apex\Test\WebSocket\Support\FakeWsConnectionStore;
use Hyperf\Apex\Test\WebSocket\Support\FakeWsRoomStore;
use Hyperf\Apex\WebSocket\Contract\WsConnectionStoreInterface;
use Hyperf\Config\Config;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * WebSocket 采样指标的契约。
 *
 * 两个要点：空闲判据必须与网关同一口径（活跃时间早于「现在 - idle_timeout」），
 * 以及索引表不可用时整组不输出 —— 输出 0 会看起来像「一条连接都没有」。
 */
final class WebSocketGaugeCollectorTest extends TestCase
{
    public function testCollectsConnectionMetrics(): void
    {
        $store = new FakeWsConnectionStore();
        $store->connectionCount = 5;
        $store->userCount = 3;
        $store->idle = ['ws:1', 'ws:2'];
        $roomStore = new FakeWsRoomStore();
        $roomStore->roomCount = 2;

        $samples = $this->collector($store, tableSize: 65536, idleTimeout: 180, roomStore: $roomStore)->collect();

        $this->assertSame(5, $this->valueOf($samples, 'core_ws_connections'));
        $this->assertSame(3, $this->valueOf($samples, 'core_ws_online_users'));
        $this->assertSame(2, $this->valueOf($samples, 'core_ws_rooms'));
        $this->assertSame(65536, $this->valueOf($samples, 'core_ws_connection_capacity'));
        $this->assertSame(2, $this->valueOf($samples, 'core_ws_idle_connections'));

        // 判据口径与网关一致：早于「现在 - 回收时限」的连接才算空闲。
        $this->assertCount(1, $store->idleQueries);
        $this->assertEqualsWithDelta(time() - 180, $store->idleQueries[0], 2);
    }

    public function testIdleCountIsZeroWhenRecycleIsDisabled(): void
    {
        $store = new FakeWsConnectionStore();
        $store->idle = ['ws:1'];

        $samples = $this->collector($store, tableSize: 1024, idleTimeout: 0)->collect();

        // 没有回收机制就没有「等待回收」这个状态，也不该去查索引。
        $this->assertSame([], $store->idleQueries);
        $this->assertSame(0, $this->valueOf($samples, 'core_ws_idle_connections'));
    }

    public function testReturnsNothingWhenConnectionStoreIsUnavailable(): void
    {
        // 装了 metrics 但没启用网关（或没启动 server）时索引表不存在：
        // 整组不输出，而不是给出一串 0。
        $store = $this->createMock(WsConnectionStoreInterface::class);
        $store->method('countConnections')->willThrowException(new RuntimeException('连接索引尚未初始化'));

        $this->assertSame([], $this->collector($store, tableSize: 1024, idleTimeout: 60)->collect());
    }

    private function collector(
        WsConnectionStoreInterface $store,
        int $tableSize,
        int $idleTimeout,
        ?FakeWsRoomStore $roomStore = null,
    ): WebSocketGaugeCollector {
        return new WebSocketGaugeCollector($store, $roomStore ?? new FakeWsRoomStore(), new Config([
            'websocket' => [
                'table_size' => $tableSize,
                'idle_timeout' => $idleTimeout,
            ],
        ]));
    }

    /**
     * @param list<GaugeSample> $samples
     */
    private function valueOf(array $samples, string $name): float|int|null
    {
        foreach ($samples as $sample) {
            if ($sample->name === $name) {
                return $sample->value;
            }
        }

        return null;
    }
}
