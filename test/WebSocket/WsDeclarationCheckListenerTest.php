<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket;

use Hyperf\Apex\Test\WebSocket\Support\WsAnnotationRegistrar;
use Hyperf\Apex\Test\WebSocket\Support\WsTestConfig;
use Hyperf\Apex\WebSocket\Listener\WsDeclarationCheckListener;
use Hyperf\Apex\WebSocket\Registry\WsGatewayScanner;
use Hyperf\Apex\WebSocket\Registry\WsHandlerRegistry;
use Hyperf\Framework\Event\BootApplication;
use Hyperf\Server\ServerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 声明检查的触发口径。
 *
 * 「零入口」本身不是错误：monorepo 的元包会一并装上本包，只用 HTTP 的应用不该因此起不来。
 * 真正要拦的是「server.php 里配了 WebSocket 服务却没有任何入口」—— 那种情况下客户端握手
 * 只会拿到 404，而从启动日志里看不出任何异常。
 */
final class WsDeclarationCheckListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        WsAnnotationRegistrar::clear();
    }

    public function testDeclaredWebSocketServerWithoutAnyGatewayIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('但没有任何 #[WsGateway] 入口');

        $this->listener($this->webSocketServerConfig())->process(new BootApplication());
    }

    public function testNoGatewayAndNoWebSocketServerIsAllowed(): void
    {
        // 只用 HTTP 的应用：既没有入口也没有 ws 服务，声明检查应当直接放行。
        $this->listener()->process(new BootApplication());

        $this->addToAssertionCount(1);
    }

    public function testDisabledGatewaySkipsEveryCheck(): void
    {
        $this->listener($this->webSocketServerConfig(), ['enabled' => false])->process(new BootApplication());

        $this->addToAssertionCount(1);
    }

    public function testHandlerAndGatewayErrorsAreReportedInOneRound(): void
    {
        // 「一次报完，而不是报一个改一个」是这个监听器存在的意义。
        WsAnnotationRegistrar::route(EnvelopePayloadHandler::class, 'handle', 'Bad-Event');

        try {
            $this->listener($this->webSocketServerConfig())->process(new BootApplication());
            $this->fail('声明写错时必须抛异常。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('不符合 {domain}.{action} 格式', $exception->getMessage());
            $this->assertStringContainsString('没有任何 #[WsGateway] 入口', $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $websocket
     */
    private function listener(array $extra = [], array $websocket = []): WsDeclarationCheckListener
    {
        $config = WsTestConfig::make($websocket, $extra);

        return new WsDeclarationCheckListener($config, new WsGatewayScanner($config), new WsHandlerRegistry());
    }

    /**
     * @return array<string, mixed>
     */
    private function webSocketServerConfig(): array
    {
        return ['server' => ['servers' => [['name' => 'ws', 'type' => ServerInterface::SERVER_WEBSOCKET]]]];
    }
}

/** 只用于制造「事件名格式错误」的处理器。 */
final class EnvelopePayloadHandler
{
    public function handle(): void
    {
    }
}
