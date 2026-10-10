<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket;

use Hyperf\Apex\Test\WebSocket\Support\WsAnnotationRegistrar;
use Hyperf\Apex\WebSocket\Attribute\WsGateway;
use Hyperf\Apex\WebSocket\Exception\WsDeclarationException;
use Hyperf\Apex\WebSocket\Registry\WsGatewayScanner;
use Hyperf\Config\Config;
use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
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
        $this->register(ValidGateway::class, '/ws/device', 'device', name: '设备长连接');

        $definitions = $this->scanner()->all();
        $this->assertArrayHasKey(ValidGateway::class, $definitions);

        $definition = $definitions[ValidGateway::class];
        $this->assertSame('/ws/device', $definition->path);
        $this->assertSame('device', $definition->endpoint);
        $this->assertSame('设备长连接', $definition->displayName());
    }

    public function testDisplayNameFallsBackToPath(): void
    {
        $this->register(ValidGateway::class, '/ws/device', 'device');

        $this->assertSame('/ws/device', $this->scanner()->all()[ValidGateway::class]->displayName());
    }

    public function testEmptyCollectionIsRejected(): void
    {
        // 一个入口都没有时，配了 ws 服务也没有任何路径可以握手。
        $this->assertDeclarationError('至少需要一个 #[WsGateway] 入口');
    }

    public function testPathMustStartWithSlash(): void
    {
        $this->register(ValidGateway::class, 'ws/device', 'device');

        $this->assertDeclarationError('必须以 / 开头');
    }

    public function testPathMustStayUnderConfiguredPrefix(): void
    {
        $this->register(ValidGateway::class, '/other/device', 'device');

        $this->assertDeclarationError('不在 websocket.path');
    }

    public function testEndpointIsRequired(): void
    {
        $this->register(ValidGateway::class, '/ws/device', '');

        $this->assertDeclarationError('未声明 endpoint');
    }

    public function testServerMustExistInServerConfig(): void
    {
        $this->register(ValidGateway::class, '/ws/device', 'device', server: 'socket');

        $this->assertDeclarationError('在 config/autoload/server.php 里不存在');
    }

    public function testDuplicatePathAndEndpointAreRejected(): void
    {
        $this->register(ValidGateway::class, '/ws/device', 'device');
        $this->register(SecondGateway::class, '/ws/device', 'device');

        $errors = implode("\n", $this->declarationErrors());
        $this->assertStringContainsString('入口 (server=ws, path=/ws/device) 重复', $errors);
        $this->assertStringContainsString('endpoint「device」重复', $errors);
    }

    public function testLifecycleInterfacesAreRequired(): void
    {
        $this->register(NotAGateway::class, '/ws/device', 'device');

        $errors = implode("\n", $this->declarationErrors());
        $this->assertStringContainsString(OnOpenInterface::class, $errors);
        $this->assertStringContainsString(OnMessageInterface::class, $errors);
        $this->assertStringContainsString(OnCloseInterface::class, $errors);
    }

    public function testMiddlewareMustExist(): void
    {
        $this->register(ValidGateway::class, '/ws/device', 'device', middlewares: ['App\Nope\Missing']);

        $this->assertDeclarationError('中间件 App\Nope\Missing 不存在');
    }

    public function testMiddlewareMustImplementMiddlewareInterface(): void
    {
        // Hyperf 对不合规的中间件不报错、只是静默不执行 —— 结果是握手鉴权被跳过而没有迹象。
        $this->register(ValidGateway::class, '/ws/device', 'device', middlewares: [NotAMiddleware::class]);

        $this->assertDeclarationError('未实现 Psr\Http\Server\MiddlewareInterface');
    }

    public function testCompliantMiddlewarePassesValidation(): void
    {
        $this->register(ValidGateway::class, '/ws/device', 'device', middlewares: [NoopMiddleware::class]);

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
        return new WsGatewayScanner(new Config([
            'server' => ['servers' => [['name' => 'ws']]],
            'websocket' => ['path' => '/ws'],
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
