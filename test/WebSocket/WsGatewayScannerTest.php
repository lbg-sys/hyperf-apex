<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket;

use Hyperf\Apex\Test\WebSocket\Support\WsAnnotationRegistrar;
use Hyperf\Apex\Test\WebSocket\Support\WsTestConfig;
use Hyperf\Apex\WebSocket\Attribute\WsGateway;
use Hyperf\Apex\WebSocket\Exception\WsDeclarationException;
use Hyperf\Apex\WebSocket\Registry\WsGatewayScanner;
use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
use Hyperf\Server\ServerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * `#[WsGateway]` 的启动期校验。
 *
 * 这些检查的价值在于把问题从「客户端握手拿到 404」「中间件静默不执行」提前到启动期：
 * server 名写错、路径不在 websocket.path 之下、漏实现生命周期接口、中间件不合规 ——
 * 任何一条漏掉，现象都在运行期且很难定位。
 */
final class WsGatewayScannerTest extends TestCase
{
    protected function tearDown(): void
    {
        WsAnnotationRegistrar::clear();
    }

    public function testValidGatewayIsScanned(): void
    {
        $this->register(ValidGateway::class, '/ws/hub', 'hub', name: '主连接');

        $definitions = $this->scanner()->all();
        $this->assertArrayHasKey(ValidGateway::class, $definitions);

        $definition = $definitions[ValidGateway::class];
        $this->assertSame('/ws/hub', $definition->path);
        $this->assertSame('hub', $definition->endpoint);
        $this->assertSame('主连接', $definition->displayName());
    }

    public function testDisplayNameFallsBackToPath(): void
    {
        $this->register(ValidGateway::class, '/ws/hub', 'hub');

        $this->assertSame('/ws/hub', $this->scanner()->all()[ValidGateway::class]->displayName());
    }

    public function testEmptyCollectionIsNotAnError(): void
    {
        // 装了元包但根本不用长连接的应用（server.php 里没有 WebSocket 服务）不该因此起不来，
        // 所以扫描器对「零入口」放行；真正该拦的是「配了 WebSocket 服务却没有入口」，
        // 由 WsDeclarationCheckListener 判定（见 WsDeclarationCheckListenerTest）。
        $this->assertSame([], $this->scanner()->all());
    }

    public function testNonWebSocketServerIsRejected(): void
    {
        // 握手回调只挂在 WebSocket 服务上：「名字存在」是弱校验，注解写成 http 端口一样能通过，
        // 但客户端握手只会拿到 404。
        $this->register(ValidGateway::class, '/ws/hub', 'hub', server: 'http');

        $this->assertDeclarationError('不是 WebSocket 服务');
    }

    public function testPathMustStartWithSlash(): void
    {
        $this->register(ValidGateway::class, 'ws/hub', 'hub');

        $this->assertDeclarationError('必须以 / 开头');
    }

    public function testPathMustStayUnderConfiguredPrefix(): void
    {
        $this->register(ValidGateway::class, '/other/hub', 'hub');

        $this->assertDeclarationError('不在 websocket.path');
    }

    public function testEndpointIsRequired(): void
    {
        $this->register(ValidGateway::class, '/ws/hub', '');

        $this->assertDeclarationError('未声明 endpoint');
    }

    public function testServerMustExistInServerConfig(): void
    {
        $this->register(ValidGateway::class, '/ws/hub', 'hub', server: 'socket');

        $this->assertDeclarationError('在 config/autoload/server.php 里不存在');
    }

    public function testDuplicatePathAndEndpointAreRejected(): void
    {
        $this->register(ValidGateway::class, '/ws/hub', 'hub');
        $this->register(SecondGateway::class, '/ws/hub', 'hub');

        $errors = implode("\n", $this->declarationErrors());
        $this->assertStringContainsString('入口 (server=ws, path=/ws/hub) 重复', $errors);
        $this->assertStringContainsString('endpoint「hub」重复', $errors);
    }

    public function testLifecycleInterfacesAreRequired(): void
    {
        $this->register(NotAGateway::class, '/ws/hub', 'hub');

        $errors = implode("\n", $this->declarationErrors());
        $this->assertStringContainsString(OnOpenInterface::class, $errors);
        $this->assertStringContainsString(OnMessageInterface::class, $errors);
        $this->assertStringContainsString(OnCloseInterface::class, $errors);
    }

    public function testMiddlewareMustExist(): void
    {
        $this->register(ValidGateway::class, '/ws/hub', 'hub', middlewares: ['App\Nope\Missing']);

        $this->assertDeclarationError('中间件 App\Nope\Missing 不存在');
    }

    public function testMiddlewareMustImplementMiddlewareInterface(): void
    {
        // Hyperf 对不合规的中间件不报错、只是静默不执行 —— 结果是握手鉴权被跳过而没有迹象。
        $this->register(ValidGateway::class, '/ws/hub', 'hub', middlewares: [NotAMiddleware::class]);

        $this->assertDeclarationError('未实现 Psr\Http\Server\MiddlewareInterface');
    }

    public function testCompliantMiddlewarePassesValidation(): void
    {
        $this->register(ValidGateway::class, '/ws/hub', 'hub', middlewares: [NoopMiddleware::class]);

        $definition = $this->scanner()->all()[ValidGateway::class];
        $this->assertSame([NoopMiddleware::class], $definition->middlewares);
    }

    /**
     * @param list<string> $middlewares
     */
    private function register(
        string $class,
        string $path,
        string $endpoint,
        string $server = 'ws',
        array $middlewares = [],
        string $name = '',
    ): void {
        WsAnnotationRegistrar::gateway($class, new WsGateway(
            path: $path,
            server: $server,
            middlewares: $middlewares,
            endpoint: $endpoint,
            name: $name,
        ));
    }

    private function assertDeclarationError(string $expected): void
    {
        $errors = $this->declarationErrors();

        $this->assertNotSame([], $errors, '声明校验应当失败，但没有抛 WsDeclarationException。');
        $this->assertStringContainsString($expected, implode("\n", $errors));
    }

    /** @return list<string> */
    private function declarationErrors(): array
    {
        try {
            $this->scanner()->all();
        } catch (WsDeclarationException $exception) {
            return $exception->errors();
        }

        $this->fail('声明校验应当失败，但没有抛 WsDeclarationException。');
    }

    private function scanner(): WsGatewayScanner
    {
        return new WsGatewayScanner(WsTestConfig::make([], [
            'server' => [
                'servers' => [
                    ['name' => 'http', 'type' => ServerInterface::SERVER_HTTP],
                    ['name' => 'ws', 'type' => ServerInterface::SERVER_WEBSOCKET],
                ],
            ],
        ]));
    }
}

/** 同时实现三个生命周期契约的最小入口。 */
class LifecycleGateway implements OnOpenInterface, OnMessageInterface, OnCloseInterface
{
    public function onOpen($server, $request): void
    {
    }

    public function onMessage($server, $frame): void
    {
    }

    public function onClose($server, int $fd, int $reactorId): void
    {
    }
}

final class ValidGateway extends LifecycleGateway
{
}

final class SecondGateway extends LifecycleGateway
{
}

/** 缺三个生命周期接口：要等到第一条消息或第一次断开才炸。 */
final class NotAGateway
{
}

final class NotAMiddleware
{
}

/** 合规的中间件替身：存在且实现了 MiddlewareInterface。 */
final class NoopMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}
