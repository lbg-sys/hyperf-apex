<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket;

use Hyperf\Apex\Test\WebSocket\Support\WsTestConfig;
use Hyperf\Apex\WebSocket\Listener\WsRuntimeCheckListener;
use Hyperf\Framework\Event\BootApplication;
use Hyperf\Server\CoroutineServer;
use Hyperf\Server\Server;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 运行配置启动检查：这几项配错了都不会当场报错，只会在某个时刻静默失效
 * （推送丢一部分、超限帧变成断连、连接表写满后拒绝新连接、房间索引驱动解析不了），
 * 所以必须在启动期拦住。
 */
final class WsRuntimeCheckListenerTest extends TestCase
{
    public function testCoroutineServerWithMultipleWorkersIsRejected(): void
    {
        // 协程服务器的 Sender 只能在当前 worker 内投递，多 worker 下大部分推送会静默丢失。
        $this->assertRuntimeError($this->listener(extra: [
            'server' => ['type' => CoroutineServer::class, 'settings' => ['worker_num' => 4]],
        ]), 'worker_num=4');
    }

    public function testMultiWorkerCanBeExplicitlyAccepted(): void
    {
        $this->listener(['allow_multi_worker' => true], [
            'server' => ['type' => CoroutineServer::class, 'settings' => ['worker_num' => 4]],
        ])->process(new BootApplication());

        $this->addToAssertionCount(1);
    }

    public function testTraditionalServerIsNotRestrictedByWorkerCount(): void
    {
        // 传统 Swoole\Server 下 Sender 会广播 PipeMessage 交给持有连接的 worker，多 worker 是通的。
        $this->listener(extra: [
            'server' => ['type' => Server::class, 'settings' => ['worker_num' => 4]],
        ])->process(new BootApplication());

        $this->addToAssertionCount(1);
    }

    public function testFrameCeilingMustFitInsidePackageLimit(): void
    {
        // 超过协议层上限的帧在 Swoole 那里就被断开了，客户端拿到的是断连而不是错误信封。
        $this->assertRuntimeError($this->listener(extra: [
            'server' => ['settings' => ['package_max_length' => 8192]],
        ]), 'package_max_length');
    }

    public function testFrameCeilingBelowPackageLimitPasses(): void
    {
        $this->listener(['max_frame_bytes' => 8192], [
            'server' => ['settings' => ['package_max_length' => 65536]],
        ])->process(new BootApplication());

        $this->addToAssertionCount(1);
    }

    public function testIdleRecycleMustExistSomewhere(): void
    {
        $this->assertRuntimeError($this->listener(['idle_timeout' => 0]), 'idle_timeout=0');
    }

    public function testSwooleHeartbeatSatisfiesIdleRecycleOnClassicServer(): void
    {
        // 传统 Swoole\Server 下 heartbeat_idle_time 真的会断掉空闲连接，可以顶替应用层回收。
        $this->listener(['idle_timeout' => 0], [
            'server' => ['type' => Server::class, 'settings' => ['heartbeat_idle_time' => 60]],
        ])->process(new BootApplication());

        $this->addToAssertionCount(1);
    }

    public function testSwooleHeartbeatDoesNotSaveACoroutineServer(): void
    {
        // 协程服务器用的是 Swoole\Coroutine\Http\Server，它的 set() 只收 HTTP 层选项，
        // heartbeat_idle_time 不会生效 —— 拿它当豁免条件等于放行一份「零回收」的配置，
        // 而定长连接表写满后新连接会被直接拒绝且不自愈。
        $this->assertRuntimeError($this->listener(['idle_timeout' => 0], [
            'server' => ['type' => CoroutineServer::class, 'settings' => ['heartbeat_idle_time' => 60]],
        ]), 'idle_timeout=0');
    }

    public function testUnknownRoomStoreDriverIsRejected(): void
    {
        // 驱动名写错时既不报错也不生效（房间索引永远走默认实现）是最坏的结果，必须在启动期拦住。
        $this->assertRuntimeError($this->listener(['room_store_driver' => 'memcached']), 'room_store_driver 只能是');
    }

    public function testRedisRoomStoreDriverPassesWhenRedisClientIsInstalled(): void
    {
        // hyperf/redis 在本仓库里是装着（reliability 依赖它），所以这一项只验证「认得 redis 这个驱动」。
        // 「没装 hyperf/redis」的分支由 WsRoomStoreTest 直接断言 WsRoomStore::assertDriver()。
        $this->listener(['room_store_driver' => 'redis'])->process(new BootApplication());

        $this->addToAssertionCount(1);
    }

    public function testDisabledGatewaySkipsChecks(): void
    {
        // 开关关闭代表这套网关不参与本次启动，此时任何运行参数都不该拦启动。
        $this->listener(['enabled' => false, 'idle_timeout' => 0])->process(new BootApplication());

        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, mixed> $websocket websocket.* 覆盖项
     * @param array<string, mixed> $extra 其它一级配置键（如 server）
     */
    private function listener(array $websocket = [], array $extra = []): WsRuntimeCheckListener
    {
        return new WsRuntimeCheckListener(WsTestConfig::make($websocket, $extra));
    }

    private function assertRuntimeError(WsRuntimeCheckListener $listener, string $expected): void
    {
        try {
            $listener->process(new BootApplication());
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($expected, $exception->getMessage());

            return;
        }

        $this->fail('运行配置检查应当失败，但没有抛 RuntimeException。');
    }
}
