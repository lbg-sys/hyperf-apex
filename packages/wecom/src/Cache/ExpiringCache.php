<?php

declare(strict_types=1);

namespace Hyperf\Apex\WeCom\Cache;

use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;

use function time;

/**
 * 提前过期的 PSR-16 装饰器，只用来装 access_token。
 *
 * 企微的 access_token 按企业全局唯一：同一企业在别的系统里重新获取会把这个 token 顶掉，
 * 也就是说它的「实际有效期」比 `expires_in` 短，缓存到点才换必然会拿到一个已被顶掉的 token。
 * 这里统一把 TTL 减去一段余量（默认 300 秒），宁可提前换一个新的。
 *
 * 只改 `set` / `setMultiple` 的 TTL，其余方法原样透传：EasyWeChat 对缓存的用法只有
 * get / set 两个动作。TTL 为 null（永不过期）时不减 —— 那是调用方明确要求的语义。
 */
final class ExpiringCache implements CacheInterface
{
    /**
     * 缩短后的下限（秒）。
     *
     * `expires_in` 异常偏小时（企微没有这么短的 token，但响应可能被中间设备改写）也要留一个
     * 能用的缓存窗口，否则每个请求都要重新换 token，反而更容易把 token 换来换去。
     */
    private const MIN_TTL_SECONDS = 60;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $aheadSeconds,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($key, $default);
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        return $this->cache->set($key, $value, $this->shorten($ttl));
    }

    public function delete(string $key): bool
    {
        return $this->cache->delete($key);
    }

    public function clear(): bool
    {
        return $this->cache->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->cache->getMultiple($keys, $default);
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        return $this->cache->setMultiple($values, $this->shorten($ttl));
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->cache->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        return $this->cache->has($key);
    }

    private function shorten(DateInterval|int|null $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        $seconds = $ttl instanceof DateInterval
            ? (new DateTimeImmutable())->add($ttl)->getTimestamp() - time()
            : $ttl;

        if ($seconds <= 0) {
            return $seconds;
        }

        return max(self::MIN_TTL_SECONDS, $seconds - $this->aheadSeconds);
    }
}
