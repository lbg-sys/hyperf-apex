<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\RateLimit;

use Hyperf\Apex\RateLimit\RateLimiter;
use Hyperf\Apex\RateLimit\RateLimitResult;
use Hyperf\Apex\RateLimit\RateLimitRule;
use Hyperf\Apex\RateLimit\RateLimitScopes;
use Hyperf\Apex\Test\Support\StubLoggerFactory;
use Hyperf\Config\Config;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 令牌桶限流器 PHP 侧的编排契约（不跑真实 Redis）。
 *
 * 令牌桶的「读—算—写」在 Lua 里，这里钉的是它周围那圈容易被改坏的东西：
 * - Lua 返回 `{allowed, remaining, retryAfter}` 被正确解读成 RateLimitResult；
 * - evalSha 缓存丢失时自动回落 eval（phpredis 不会自己回落）；
 * - Redis 不可达时按 failure_mode 降级；
 * - 毫令牌换算（1 令牌 = 1000 毫令牌）与桶 key 的组装。
 *
 * Redis 用替身（FakeRedisProxy / FakeRedisFactory）：Lua 本身不在单测里执行，
 * 那需要真实 Redis 或 Lua 解释器，交给集成环境。
 */
final class RateLimiterAttemptTest extends TestCase
{
    public function testAllowedResultIsDecoded(): void
    {
        $proxy = $this->proxy(static fn (): array => [1, 5, 0]);

        $result = $this->limiter($proxy)->attempt('10.0.0.1', $this->rule());

        $this->assertTrue($result->allowed);
        $this->assertSame(10, $result->limit);
        $this->assertSame(5, $result->remaining);
        $this->assertSame(0, $result->retryAfterSeconds);
    }

    public function testRejectedResultIsDecoded(): void
    {
        $proxy = $this->proxy(static fn (): array => [0, 0, 3]);

        $result = $this->limiter($proxy)->attempt('10.0.0.1', $this->rule());

        $this->assertFalse($result->allowed);
        $this->assertSame(0, $result->remaining);
        $this->assertSame(3, $result->retryAfterSeconds);
    }

    public function testEvalShaCacheMissFallsBackToEval(): void
    {
        // phpredis 在脚本缓存丢失（Redis 重启 / SCRIPT FLUSH）时不会自动回落，
        // 必须由这里捕获后改用 eval 带上脚本体重试一次。
        $proxy = $this->proxy(
            static function (): array {
                throw new RuntimeException('NOSCRIPT No matching script');
            },
            static fn (): array => [1, 5, 0],
        );

        $result = $this->limiter($proxy)->attempt('10.0.0.1', $this->rule());

        $this->assertTrue($result->allowed);
        $this->assertSame(['evalSha', 'eval'], array_column($proxy->calls, 0));
    }

    public function testRedisFailureDegradesOpenByDefault(): void
    {
        // 默认 failure_mode=open：Redis 挂了宁可放行，也不能把业务整个拖垮。
        $proxy = $this->proxy(
            static function (): array {
                throw new RuntimeException('connection refused');
            },
            static function (): array {
                throw new RuntimeException('connection refused');
            },
        );

        $result = $this->limiter($proxy)->attempt('10.0.0.1', $this->rule());

        $this->assertTrue($result->allowed);
        $this->assertSame(10, $result->remaining);
    }

    public function testRedisFailureDegradesClosedWhenConfigured(): void
    {
        $proxy = $this->proxy(
            static function (): array {
                throw new RuntimeException('connection refused');
            },
            static function (): array {
                throw new RuntimeException('connection refused');
            },
        );

        $result = $this->limiter($proxy, ['failure_mode' => 'close'])->attempt('10.0.0.1', $this->rule());

        $this->assertFalse($result->allowed);
        $this->assertSame(1, $result->retryAfterSeconds);
    }

    public function testKeyAndArgumentsAreAssembledCorrectly(): void
    {
        $proxy = $this->proxy(static fn (): array => [1, 5, 0]);

        $this->limiter($proxy, ['prefix' => 'test:rl:'])->attempt('10.0.0.1', $this->rule());

        // evalSha(scriptSha, argsList, numKeys)
        $this->assertSame('evalSha', $proxy->calls[0][0]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) $proxy->calls[0][1][0]);

        $arguments = $proxy->calls[0][1][1];
        $this->assertSame('test:rl:login:ip:' . hash('sha256', '10.0.0.1'), $arguments[0]);
        $this->assertSame('10000', $arguments[1], '容量 10 令牌 = 10000 毫令牌');
        $this->assertSame('2', $arguments[2], 'refill 原样传，Lua 侧换算');
        $this->assertSame('1000', $arguments[4], '消耗 1 令牌 = 1000 毫令牌');

        $this->assertSame(1, $proxy->calls[0][1][2], '只有 KEYS[1]，numKeys = 1');
    }

    private function rule(): RateLimitRule
    {
        return new RateLimitRule(name: 'login', scope: RateLimitScopes::IP, capacity: 10, refill: 2, consume: 1);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function limiter(FakeRedisProxy $proxy, array $config = []): RateLimiter
    {
        return new RateLimiter(
            new FakeRedisFactory($proxy),
            new Config(['rate_limit' => array_merge([
                'redis_pool' => 'default',
                'prefix' => 'core:rl:',
                'failure_mode' => 'open',
            ], $config)]),
            StubLoggerFactory::create('shared'),
        );
    }

    /**
     * @param list<callable(): mixed> $handlers 每次 __call 依次消耗一个
     */
    private function proxy(callable ...$handlers): FakeRedisProxy
    {
        $proxy = new FakeRedisProxy();
        $proxy->handlers = $handlers;

        return $proxy;
    }
}

/**
 * 替身：`__call` 拦截 evalSha / eval，不建连接。
 *
 * `RedisProxy` 的构造需要 PoolFactory，这里直接跳过父构造 —— 只要不碰
 * `RedisConnection::$connection`（由 __call 惰性解析），就不会去连 Redis。
 */
final class FakeRedisProxy extends RedisProxy
{
    /** @var list<callable(): mixed> */
    public array $handlers = [];

    /** @var list<array{0: string, 1: array}> */
    public array $calls = [];

    public function __construct()
    {
        // 跳过父构造。
    }

    public function __call(string $name, array $arguments): mixed
    {
        $this->calls[] = [$name, $arguments];

        if ($this->handlers === []) {
            throw new RuntimeException('没有配置响应处理器');
        }

        return (array_shift($this->handlers))();
    }
}

/** 替身：get() 固定返回注入的代理，不读连接池。 */
final class FakeRedisFactory extends RedisFactory
{
    public function __construct(private readonly RedisProxy $proxy)
    {
        // 跳过父构造：父构造会用全局 make() 建连接池，替身不需要。
    }

    public function get(string $poolName): RedisProxy
    {
        return $this->proxy;
    }
}
