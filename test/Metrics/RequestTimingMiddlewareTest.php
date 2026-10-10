<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Metrics;

use Hyperf\Apex\Metrics\Middleware\RequestTimingMiddleware;
use Hyperf\Apex\Metrics\Registry\MetricDefinitionRegistry;
use Hyperf\Apex\Metrics\Scanner\HttpRoutePathScanner;
use Hyperf\Apex\Metrics\Storage\MetricTable;
use Hyperf\Apex\Test\Support\StubLoggerFactory;
use Hyperf\Config\Config;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Server\Response as ServerResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 请求计时中间件：记录一次，且重复注册也只记一次。
 *
 * 组件包会把它注册为全局中间件，应用侧可能早就自己挂过一份 —— 两条都在管线里时，
 * 没有保护就会各记一次，现象只在数字上看得出来（QPS 翻倍、access 日志翻倍）。
 */
final class RequestTimingMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        MetricTable::reset();
    }

    public function testRecordsOncePerRequest(): void
    {
        $this->initTable();

        $this->middleware()->process($this->request(), $this->handler());

        $this->assertSame(1, $this->requestsTotal());
    }

    public function testDuplicateRegistrationOnlyRecordsOnce(): void
    {
        $this->initTable();

        $handler = $this->handler();
        $inner = $this->middleware();
        $outer = $this->middleware();

        $chain = new class($inner, $handler) implements RequestHandlerInterface {
            public function __construct(
                private readonly RequestTimingMiddleware $inner,
                private readonly RequestHandlerInterface $handler,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->inner->process($request, $this->handler);
            }
        };

        $outer->process($this->request(), $chain);

        $this->assertSame(1, $this->requestsTotal(), '同一条请求管线里注册两份也不该记两次。');
    }

    private function requestsTotal(): int
    {
        $id = MetricTable::registry()?->resolve('core_http_requests_total', [
            'method' => 'GET',
            'route' => HttpRoutePathScanner::NOT_FOUND,
            'status_class' => '2xx',
        ]);

        return $id === null ? 0 : (MetricTable::snapshot()[$id] ?? 0);
    }

    /**
     * 建表的定义只需要覆盖中间件会写的两条指标；`@http_routes` 这类动态值域在这里
     * 用不到，路由标签取默认的 not_found（测试请求没有 Dispatched 属性）。
     */
    private function initTable(): void
    {
        $config = new Config([
            'metrics' => [
                'histogram_buckets' => [0.01, 0.1, 1.0],
                'definitions' => [
                    'core_http_requests_total' => [
                        'type' => 'counter',
                        'help' => 'HTTP 请求总数',
                        'labels' => [
                            'method' => ['GET', 'POST', 'OTHER'],
                            'route' => [HttpRoutePathScanner::NOT_FOUND],
                            'status_class' => ['1xx', '2xx', '3xx', '4xx', '5xx'],
                        ],
                    ],
                    'core_http_request_duration_seconds' => [
                        'type' => 'histogram',
                        'help' => 'HTTP 请求处理耗时',
                        'labels' => [
                            'method' => ['GET', 'POST', 'OTHER'],
                            'route' => [HttpRoutePathScanner::NOT_FOUND],
                        ],
                    ],
                ],
            ],
        ]);

        MetricTable::init(MetricDefinitionRegistry::fromConfig($config), 1024);
    }

    private function middleware(): RequestTimingMiddleware
    {
        return new RequestTimingMiddleware(
            StubLoggerFactory::create('access'),
            new Config(['access_log' => ['exclude' => []]]),
        );
    }

    private function request(): Request
    {
        return new Request('GET', '/any');
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new ServerResponse();
            }
        };
    }
}
