<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\RateLimit;

use FastRoute\Dispatcher;
use Hyperf\Apex\Http\ClientIpResolver;
use Hyperf\Apex\Http\HandlerMethodResolver;
use Hyperf\Apex\Http\IpRuleMatcher;
use Hyperf\Apex\RateLimit\Attribute\RateLimit;
use Hyperf\Apex\RateLimit\Middleware\RateLimitMiddleware;
use Hyperf\Apex\RateLimit\RateLimiter;
use Hyperf\Apex\RateLimit\RateLimitIdentityResolverRegistry;
use Hyperf\Apex\RateLimit\RateLimitRuleReader;
use Hyperf\Apex\RateLimit\RateLimitScopes;
use Hyperf\Apex\RateLimit\Resolver\GlobalIdentityResolver;
use Hyperf\Config\Config;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ContainerInterface;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Server\Response;
use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\HttpServer\Router\Handler;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Redis\Pool\PoolFactory;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * 限流中间件的决策分支（不依赖真实 Redis 的部分）。
 *
 * 中间件在触碰 `RateLimiter::attempt()` 之前还有一层前置判断：开关、路由是否匹配、
 * 方法有没有声明规则、来源是否在白名单、主体标识能否解析。这些分支都会**直接放行**，
 * 而且放行时不该带任何 X-RateLimit-* 头 —— 带上去等于宣称限流生效了，实际根本没限。
 *
 * `attempt()` 之后的判定（允许/拒绝/降级）需要 Redis，交给集成环境或 Redis 测试替身，
 * 不在本组用例范围内。
 */
final class RateLimitMiddlewareTest extends TestCase
{
    public function testDisabledMiddlewarePassesThrough(): void
    {
        $middleware = $this->middleware(['enabled' => false]);

        $response = $middleware->process($this->request($this->dispatched('limited')), $this->handler());

        $this->assertFalse($response->hasHeader('X-RateLimit-Limit'), '关闭后不该宣称限流生效。');
    }

    public function testUnmatchedRoutePassesThrough(): void
    {
        // 没有 Dispatched 属性 = 路由没匹配，无从谈起限流。
        $response = $this->middleware()->process($this->request(null), $this->handler());

        $this->assertFalse($response->hasHeader('X-RateLimit-Limit'));
    }

    public function testMethodWithoutRulesPassesThrough(): void
    {
        // 方法没声明 #[RateLimit] 就不限流，也不该带限流头。
        $response = $this->middleware()->process($this->request($this->dispatched('unlimited')), $this->handler());

        $this->assertFalse($response->hasHeader('X-RateLimit-Limit'));
    }

    public function testWhitelistedRequestPassesThrough(): void
    {
        $middleware = $this->middleware(['whitelist_ips' => ['127.0.0.1']]);

        $response = $middleware->process($this->request($this->dispatched('limited'), '127.0.0.1'), $this->handler());

        $this->assertFalse($response->hasHeader('X-RateLimit-Limit'), '白名单豁免后不该带限流头。');
    }

    public function testUnresolvableIdentitySkipsTheRule(): void
    {
        // 规则按 ip 维度，但注册表里只有 global 解析器：宁可不限流，也不能错限流。
        // 退化成共享桶会让所有主体共用一个配额，是比不限流更糟的错误。
        $response = $this->middleware()->process($this->request($this->dispatched('limited')), $this->handler());

        $this->assertFalse($response->hasHeader('X-RateLimit-Limit'));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function middleware(array $overrides = []): RateLimitMiddleware
    {
        // 限流中间件、限流器、解析器注册表、RedisFactory 都走同一个容器：
        // 它的 make() 同时提供日志类（formatter/handler/logger）与惰性 RedisProxy，
        // get() 提供限流维度解析器。
        $container = $this->container();
        ApplicationContext::setContainer($container);

        $config = new Config([
            'rate_limit' => array_merge([
                'enabled' => true,
                'resolvers' => [GlobalIdentityResolver::class],
                'whitelist_ips' => [],
                'prefix' => 'test:rl:',
                'failure_mode' => 'open',
            ], $overrides),
            // RedisFactory 只要有一个池配置就会建一个惰性代理；本组用例不触 attempt()，不会真的连。
            'redis' => ['default' => ['host' => '127.0.0.1', 'port' => 6379]],
        ]);

        // 通道要 shared：限流中间件与限流器都用 `get('rate_limit', 'shared')`。
        $loggerFactory = new LoggerFactory($container, new Config([
            'logger' => ['default' => 'shared', 'channels' => ['shared' => []]],
        ]));

        return new RateLimitMiddleware(
            $loggerFactory,
            $config,
            new HandlerMethodResolver(),
            new RateLimitRuleReader(),
            new RateLimitIdentityResolverRegistry($container, $config),
            new RateLimiter(new RedisFactory($config), $config, $loggerFactory),
            new ClientIpResolver($config, new IpRuleMatcher()),
            new IpRuleMatcher(),
        );
    }

    private function container(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                return match ($id) {
                    GlobalIdentityResolver::class => new GlobalIdentityResolver(),
                    default => throw new RuntimeException('容器里没有 ' . $id),
                };
            }

            public function has(string $id): bool
            {
                return $id === GlobalIdentityResolver::class;
            }

            public function make(string $name, array $parameters = []): mixed
            {
                if ($name === RedisProxy::class) {
                    return new RedisProxy(new PoolFactory($this), $parameters['pool'] ?? 'default');
                }

                // 日志旁路：LoggerFactory 会为 shared 通道构造 formatter / handler / logger。
                if (str_contains($name, 'Formatter')) {
                    return new LineFormatter();
                }
                if (str_contains($name, 'Handler')) {
                    return new NullHandler();
                }

                return new NullLogger();
            }

            public function set(string $name, mixed $entry): void
            {
            }

            public function unbind(string $name): void
            {
            }

            public function define(string $name, mixed $definition): void
            {
            }
        };
    }

    private function request(?Dispatched $dispatched, ?string $remoteAddr = null): ServerRequestInterface
    {
        $request = new Request('GET', '/fixture');
        if ($dispatched !== null) {
            $request = $request->withAttribute(Dispatched::class, $dispatched);
        }
        if ($remoteAddr !== null) {
            $request = $request->withServerParams(['remote_addr' => $remoteAddr]);
        }

        return $request;
    }

    private function dispatched(string $method): Dispatched
    {
        return new Dispatched([
            Dispatcher::FOUND,
            new Handler([RateLimitFixtureController::class, $method], '/fixture'),
            [],
        ]);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };
    }
}

/** 一条声明了限流的入口 + 一条没声明的，用于区分「有规则」与「无规则」两个分支。 */
final class RateLimitFixtureController
{
    #[RateLimit(name: 'fixture_limit', scope: RateLimitScopes::IP, capacity: 10, refill: 1)]
    public function limited(): void
    {
    }

    public function unlimited(): void
    {
    }
}
