<?php

declare(strict_types=1);

namespace Hyperf\Apex\RateLimit;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Redis 令牌桶限流器。
 *
 * 令牌桶放在 Redis 而不是进程内：SWOOLE_BASE 下多 worker 各算各的，进程内计数必然
 * 算错；Swoole\Table 又没有 TTL 与 CAS。整个「读—算—写」在一段 Lua 里完成，
 * 单次 RTT 且天然原子，协程安全。
 *
 * 这里不认识任何业务概念，只认识「主体标识」这个抽象。
 */
final class RateLimiter
{
    /**
     * 1 令牌 = 1000 毫令牌。
     *
     * 令牌数是连续量，而 Redis 以字符串存储 Lua 数字，浮点往返会累积误差。折算成毫令牌
     * 后，补充量 = 间隔毫秒 × refill（令牌/秒）恰好是整数运算：refill × 1000 × Δms / 1000。
     */
    private const MILLI_TOKENS = 1000;

    /**
     * KEYS[1] 桶键
     * ARGV[1] capacity 桶容量（毫令牌）
     * ARGV[2] refill   每秒补充令牌数（令牌/秒）
     * ARGV[3] now      当前毫秒时间戳
     * ARGV[4] consume  本次消耗（毫令牌）
     * ARGV[5] ttl      键过期秒数（已由 PHP 侧加上抖动）.
     *
     * 脚本内禁止 KEYS、扫描与循环：Lua 执行期间 Redis 全局阻塞，一次慢脚本等于全库卡死。
     */
    private const SCRIPT = <<<'LUA'
local capacity = tonumber(ARGV[1])
local refill   = tonumber(ARGV[2])
local now      = tonumber(ARGV[3])
local consume  = tonumber(ARGV[4])
local ttl      = tonumber(ARGV[5])
local perSec   = refill * 1000

local data   = redis.call('HMGET', KEYS[1], 't', 's')
local tokens = tonumber(data[1])
local stamp  = tonumber(data[2])
if tokens == nil then tokens = capacity end
if stamp  == nil then stamp  = now      end

local delta = now - stamp
if delta > 0 then
    tokens = math.min(capacity, tokens + delta * refill)
end

local allowed, retry = 0, 0
if tokens >= consume then
    allowed = 1
    tokens  = tokens - consume
else
    retry = math.ceil((consume - tokens) / perSec)
end

redis.call('HSET', KEYS[1], 't', tokens, 's', now)
redis.call('EXPIRE', KEYS[1], ttl)

return { allowed, math.floor(tokens / 1000), retry }
LUA;

    private readonly RedisProxy $redis;

    private readonly string $prefix;

    private readonly bool $failOpen;

    private readonly float $ttlJitterRatio;

    private readonly string $scriptSha;

    private readonly LoggerInterface $logger;

    public function __construct(
        RedisFactory $factory,
        ConfigInterface $config,
        LoggerFactory $loggerFactory,
    ) {
        $this->logger = $loggerFactory->get('rate_limit', 'shared');
        $this->redis = $factory->get((string) $config->get('rate_limit.redis_pool', 'default'));
        $this->prefix = (string) $config->get('rate_limit.prefix', 'core:rl:');
        $this->failOpen = ((string) $config->get('rate_limit.failure_mode', 'open')) !== 'close';
        $this->ttlJitterRatio = (float) $config->get('rate_limit.ttl_jitter_ratio', 0.1);
        $this->scriptSha = sha1(self::SCRIPT);
    }

    /**
     * 尝试从桶里取令牌。
     *
     * @param string $identity 主体标识，由 scope 对应的解析器给出；内部做一次哈希，
     *                         避免过长或含特殊字符的标识污染 key 空间
     */
    public function attempt(string $identity, RateLimitRule $rule): RateLimitResult
    {
        // 数值合法性由 RateLimitRule 在构造时保证（小于 1 直接报错），这里不再做静默钳制。
        $result = $this->execute([
            $this->key($identity, $rule),
            (string) ($rule->capacity * self::MILLI_TOKENS),
            (string) $rule->refill,
            (string) $this->nowMilliseconds(),
            (string) ($rule->consume * self::MILLI_TOKENS),
            (string) $this->ttl($rule->capacity, $rule->refill),
        ]);

        if ($result === null) {
            $this->logger->error('限流脚本执行失败，已按降级策略处理。', [
                'rule' => $rule->name,
                'scope' => $rule->scope,
                'fail_open' => $this->failOpen,
            ]);

            return $this->failOpen
                ? RateLimitResult::degradeOpen($rule->capacity)
                : RateLimitResult::degradeClosed($rule->capacity);
        }

        [$allowed, $remaining, $retryAfter] = $result;

        return new RateLimitResult(
            $allowed === 1,
            $rule->capacity,
            max(0, $remaining),
            max(0, $retryAfter),
        );
    }

    /**
     * @param list<string> $arguments
     * @return null|array{int, int, int}
     */
    private function execute(array $arguments): ?array
    {
        // phpredis 的 evalSha 在脚本缓存丢失（Redis 重启、SCRIPT FLUSH）时不会自动回落，
        // 需要自己捕获后改用 eval 重新带上脚本体。
        try {
            $result = $this->redis->evalSha($this->scriptSha, $arguments, 1);
            if (is_array($result) && count($result) === 3) {
                return [(int) $result[0], (int) $result[1], (int) $result[2]];
            }
        } catch (Throwable) {
            // 脚本缓存丢失与连接失败都走这里：前者由下面的 eval 兜住，后者会再次失败并降级。
        }

        try {
            $result = $this->redis->eval(self::SCRIPT, $arguments, 1);
        } catch (Throwable $exception) {
            $this->logger->error('限流脚本调用 Redis 失败。', ['exception' => $exception]);

            return null;
        }

        if (! is_array($result) || count($result) !== 3) {
            $this->logger->error('限流脚本返回值不符合预期。', ['result' => $result]);

            return null;
        }

        return [(int) $result[0], (int) $result[1], (int) $result[2]];
    }

    private function key(string $identity, RateLimitRule $rule): string
    {
        return $this->prefix . $rule->name . ':' . $rule->scope . ':' . hash('sha256', $identity);
    }

    /**
     * 键过期时间：桶从空填满所需秒数 + 1，再叠加随机抖动。
     *
     * 同类 key 的 TTL 天然相同，突发流量会在同一秒创建大批同 TTL 的 key，不抖动就会
     * 在某一秒集中过期，给 Redis 造成主动删除的 CPU 尖峰。抖动放在 PHP 侧算，
     * 避免踩 Redis Lua 随机数在复制模式下的限制。
     */
    private function ttl(int $capacity, int $refill): int
    {
        $seconds = intdiv($capacity + $refill - 1, $refill) + 1;
        $jitter = (int) round($seconds * $this->ttlJitterRatio);

        return max(1, $seconds + random_int(-$jitter, $jitter));
    }

    private function nowMilliseconds(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
