<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WeCom\Support;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * 单测用的 PSR-16 内存实现。
 *
 * 组件对缓存的用法很窄（get / set / delete / has），而这里真正要断言的往往是
 * 「写进去的 TTL 是多少」「键名长什么样」—— 用真实驱动（Redis）既要外部服务，
 * 又看不见这两件事，所以自己实现一个并把每次写入的 TTL 记下来。
 *
 * TTL 只记录 int；传 DateInterval 或 null 时记为 null（`ExpiringCache` 会原样透传，
 * 那两种形态由它自己的用例负责断言）。键是否存在一律看 {@see self::has()}。
 */
final class InMemoryCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $items = [];

    /** @var array<string, null|int> */
    private array $ttls = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $this->items[$key] = $value;
        $this->ttls[$key] = is_int($ttl) ? $ttl : null;

        return true;
    }

    public function delete(string $key): bool
    {
        if (! array_key_exists($key, $this->items)) {
            return false;
        }

        unset($this->items[$key], $this->ttls[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];
        $this->ttls = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[(string) $key] = $this->get((string) $key, $default);
        }

        return $result;
    }

    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $allDeleted = true;
        foreach ($keys as $key) {
            $allDeleted = $this->delete((string) $key) && $allDeleted;
        }

        return $allDeleted;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    /**
     * 该键写入时用的 TTL。键不存在或写入时没给 TTL 都返回 null。
     */
    public function ttl(string $key): ?int
    {
        return $this->ttls[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }
}
