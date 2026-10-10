<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Http;

use Hyperf\Apex\Http\Middleware\CorsMiddleware;
use Hyperf\Config\Config;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Server\Response as ServerResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CorsMiddlewareTest extends TestCase
{
    public function testDefaultWildcardOriginWithoutVary(): void
    {
        $response = $this->handle($this->middleware([]), 'GET', 'https://admin.example.com');

        $this->assertSame('*', $response->getHeaderLine('access-control-allow-origin'));
        // 通配不随请求变化，声明 Vary 只会白白降低缓存命中率。
        $this->assertSame('', $response->getHeaderLine('vary'));
    }

    public function testWhitelistedOriginIsEchoedWithVary(): void
    {
        $middleware = $this->middleware(['allowed_origins' => ['https://admin.example.com']]);

        $response = $this->handle($middleware, 'GET', 'https://admin.example.com');

        $this->assertSame('https://admin.example.com', $response->getHeaderLine('access-control-allow-origin'));
        $this->assertSame('Origin', $response->getHeaderLine('vary'));
    }

    public function testOriginOutsideWhitelistGetsNoCorsHeaders(): void
    {
        $middleware = $this->middleware(['allowed_origins' => ['https://admin.example.com']]);

        $response = $this->handle($middleware, 'GET', 'https://evil.example.com');

        $this->assertSame('', $response->getHeaderLine('access-control-allow-origin'));
        $this->assertSame('', $response->getHeaderLine('access-control-allow-headers'));
    }

    public function testEmptyWhitelistSendsNoCorsHeaders(): void
    {
        $response = $this->handle($this->middleware(['allowed_origins' => []]), 'GET', 'https://admin.example.com');

        $this->assertSame('', $response->getHeaderLine('access-control-allow-origin'));
    }

    public function testAllowedHeadersComeFromConfigInsteadOfEchoingTheRequest(): void
    {
        $middleware = $this->middleware([
            'allowed_origins' => ['*'],
            'allowed_headers' => ['Content-Type', 'Idempotency-Key'],
        ]);

        $response = $this->handle($middleware, 'GET', 'https://admin.example.com', [
            'Access-Control-Request-Headers' => 'X-Evil, Content-Type',
        ]);

        $this->assertSame('Content-Type, Idempotency-Key', $response->getHeaderLine('access-control-allow-headers'));
    }

    public function testPreflightIsAnsweredWithoutHittingTheHandler(): void
    {
        $handler = new RecordingHandler();

        $request = (new Request('OPTIONS', '/api/anything'))->withHeader('origin', 'https://admin.example.com');
        $response = $this->middleware([])->process($request, $handler);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('*', $response->getHeaderLine('access-control-allow-origin'));
        $this->assertFalse($handler->called, '预检请求不应该穿透到下游处理器。');
    }

    /**
     * @param array<string, mixed> $cors
     */
    private function middleware(array $cors): CorsMiddleware
    {
        return new CorsMiddleware(new Config(['cors' => $cors]));
    }

    /**
     * @param array<string, string> $headers
     */
    private function handle(CorsMiddleware $middleware, string $method, string $origin, array $headers = []): ResponseInterface
    {
        $request = new Request($method, '/api/anything');
        if ($origin !== '') {
            $request = $request->withHeader('origin', $origin);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $middleware->process($request, new RecordingHandler());
    }
}

/** 记录是否被穿透到的下游处理器替身。 */
final class RecordingHandler implements RequestHandlerInterface
{
    public bool $called = false;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->called = true;

        return new ServerResponse();
    }
}
