<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket;

use Hyperf\Apex\WebSocket\Listener\WsRuntimeCheckListener;
use Hyperf\Config\Config;
use Hyperf\Framework\Event\BootApplication;
use Hyperf\Server\CoroutineServer;
use Hyperf\Server\Server;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 运行配置启动检查：这几项配错了都不会当场报错，只会在某个时刻静默失效
 * （推送丢一部分、超限帧变成断连、连接表写满后拒绝新连接），所以必须在启动期拦住。
 */
final class WsRuntimeCheckListenerTest extends TestCase
{
    public function testCoroutineServerWithMultipleWorkersIsRejected(): void
    {
        // 协程服务器的 Sender 只能在当前 worker 内投递，多 worker 下大部分推送会静默丢失。
        $this->assertRuntimeError($this->listener([
            'server' => ['type' => CoroutineServer::class, 'settings' => ['worker_num' => 4]],
        ]), 'worker_num=4');
    }

    public function testMultiWorkerCanBeExplicitlyAccepted(): void
    {
        $this->listener([
            'server' => ['type' => CoroutineServer::class, 'settings' => ['worker_num' => 4]],
            'websocket' => ['allow_multi_worker' => true],
        ])->process(new BootApplication());

        $this->addToAssertionCount(1);
    }

    public function testTraditionalServerIsNotRestrictedByWorkerCount(): void
    {
        // 传统 Swoole\Server 下 Sender 会广播 PipeMessage 交给持有连接的 worker，多 worker 是通的。
        $this->listener([
            'server' => ['type' => Server::class, 'settings' => ['worker_num' => 4]],
        ])->process(new BootApplication());

        $this->addToAssertionCount(1);
    }

    public function testFrameCeilingMustFitInsidePackageLimit(): void
    {
        // 超过协议层上限的帧在 Swoole 那里就被断开了，客户端拿到的是断连而不是错误信封。
        $this->assertRuntimeError($this->listener([
            'server' => ['settings' => ['package_max_length' => 8192]],
            'websocket' => ['max_frame_bytes' => 65536],
        ]), 'package_max_length');
    }

    public function testFrameCeilingBelowPackageLimitPasses(): void
    {
        $this->listener([
            'server' => ['settings' => ['package_max_length' => 65536]],
            'websocket' => ['max_frame_bytes' => 8192],
        ])->process(new BootApplication());

        $this->addToAssertionCount(1);
    }

    public function testIdleRecycleMustExistSomewhere(): void
    {
        $this->assertRuntimeError($this->listener([
            'websocket' => ['idle_timeout' => 0],
        ]), 'idle_timeout=0');
    }

    public function testSwooleHeartbeatSatisfiesIdleRecycle(): void
    {
        $this->listener([
            'server' => ['settings' => ['heartbeat_idle_time' => 60]],
            'websocket' => ['idle_timeout' => 0],
        ])->process(new BootApplication());

        $this->addToAssertionCount(1);
    }

    public function testDisabledGatewaySkipsChecks(): void
    {
        // 开关关闭代表这套网关不参与本次启动，此时任何运行参数都不该拦启动。
        $this->listener([
            'websocket' => ['enabled' => false, 'idle_timeout' => 0],
        ])->process(new BootApplication());

        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function listener(array $config): WsRuntimeCheckListener
    {
        return new WsRuntimeCheckListener(new Config($config));
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
