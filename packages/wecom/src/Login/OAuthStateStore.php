<?php

declare(strict_types=1);

namespace Hyperf\Apex\WeCom\Login;

use Hyperf\Apex\WeCom\Cache\CachePrefix;
use Hyperf\Contract\ConfigInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * 企业微信授权 state 的签发与一次性消费。
 *
 * state 只解决一件事：把回调带回来的 code 绑到「我们确实发起过这次授权」上。
 * 企微的 code 是一次性的，但任何人都能拿到属于自己的 code 并诱导别人提交 ——
 * 缺了 state 这条链路，拿到 code 就等于拿到对方的登录态。
 *
 * 键里带 alias：多企业共用一套缓存时，一个企业的 state 不能拿到另一个企业去用
 * （两个企业的 corp_id 不同，code 本来也换不出东西，但让它在第一步就失败更省事）。
 *
 * 不落库：它是几十秒内有效的短命随机值，缓存键的 TTL 天然就是它的一生。
 */
final class OAuthStateStore
{
    private readonly int $ttl;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix,
        int $ttl,
    ) {
        // 下限 60 秒：有效期短到不足以走完一次授权，只会变成「用户总说登录失败」。
        $this->ttl = max(60, $ttl);
    }

    public static function fromConfig(CacheInterface $cache, ConfigInterface $config): self
    {
        return new self(
            $cache,
            CachePrefix::fromConfig($config),
            (int) $config->get('wecom.state_ttl', 300),
        );
    }

    public function issue(string $alias): string
    {
        // 128 位随机值：state 不需要被猜中，也就不需要任何额外结构。
        $state = bin2hex(random_bytes(16));
        $this->cache->set($this->key($alias, $state), '1', $this->ttl);

        return $state;
    }

    /**
     * 消费一个 state：命中即作废并返回 true。空值与未命中都返回 false（fail-closed）。
     *
     * 用的是「删除」而不是「先读再删」：删掉成功（返回 true）就说明这个键是我们这一次删的，
     * 并发的两个请求只可能有一个拿到 true，state 也就只可能被用一次 ——
     * 这恰好是它唯一要防的那件事。先读再删会让两个请求都读到值，两个都能继续。
     */
    public function consume(string $alias, string $state): bool
    {
        $state = trim($state);
        if ($state === '') {
            return false;
        }

        return $this->cache->delete($this->key($alias, $state));
    }

    private function key(string $alias, string $state): string
    {
        return $this->prefix . 'oauth_state:' . $alias . ':' . $state;
    }
}
