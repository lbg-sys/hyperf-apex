<?php

declare(strict_types=1);

namespace Hyperf\Apex\WeCom\Cache;

use Hyperf\Apex\WeCom\Api\WeComApiClient;
use Hyperf\Contract\ConfigInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * access_token 缓存键的唯一定义，以及「让它失效」这一个动作。
 *
 * EasyWeChat 默认的键是 `work.access_token.{corpId}.{secret}` —— secret 直接进了 Redis 键名，
 * 而键名会被 KEYS、监控面板、慢查询日志原样打出来，等于把密钥另存了一份。
 * 所以由我们指定键：alias + corp_id 摘要，secret 不进键名。
 *
 * 键名里带 alias 而不是只带 corp_id：同一个 Redis 实例被多套配置共用时不会串号，
 * 也让运维在 Redis 里一眼看出是哪个企业在换 token。
 *
 * 只负责「键长什么样」与「怎么丢掉」；读和写都由 EasyWeChat 拿同一个缓存实例完成。
 */
final class AccessTokenStore
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix,
    ) {
    }

    public static function fromConfig(CacheInterface $cache, ConfigInterface $config): self
    {
        return new self($cache, CachePrefix::fromConfig($config));
    }

    public function key(string $alias, string $corpId): string
    {
        return $this->prefix . 'access_token:' . $alias . ':' . substr(hash('sha256', $corpId), 0, 16);
    }

    /**
     * 丢掉缓存里的 token。下次取 token 时会重新向企微换一个。
     *
     * 见到 40014 / 42001 时调用（见 {@see WeComApiClient}）：
     * 被同企业的其它应用顶掉是常态，重取一次就能自愈。
     */
    public function forget(string $alias, string $corpId): void
    {
        $this->cache->delete($this->key($alias, $corpId));
    }
}
