<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WeCom;

use Hyperf\Apex\Test\WeCom\Support\InMemoryCache;
use Hyperf\Apex\WeCom\Cache\AccessTokenStore;
use Hyperf\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * access_token 的缓存键。
 *
 * 键名会被 KEYS、监控面板、慢查询日志原样打出来，所以它必须满足：不含 secret、能区分企业与 alias、
 * 同一个 (alias, corp_id) 每次都算出同一个键（否则 forget 会去删另一个键，自愈就失效了）。
 */
final class AccessTokenStoreTest extends TestCase
{
    public function testKeyIsStableAndScoped(): void
    {
        $store = new AccessTokenStore(new InMemoryCache(), 'test:');

        $key = $store->key('default', 'ww-default');

        $this->assertSame($key, $store->key('default', 'ww-default'));
        $this->assertNotSame($key, $store->key('sales', 'ww-default'));
        $this->assertNotSame($key, $store->key('default', 'ww-sales'));
        $this->assertStringStartsWith('test:access_token:default:', $key);
    }

    public function testKeyLeaksNeitherSecretNorCorpId(): void
    {
        $store = new AccessTokenStore(new InMemoryCache(), 'test:');

        $key = $store->key('default', 'ww-corp-id');

        $this->assertStringNotContainsString('ww-corp-id', $key);
        $this->assertStringNotContainsString('secret', $key);
    }

    public function testForgetRemovesTheToken(): void
    {
        $cache = new InMemoryCache();
        $store = new AccessTokenStore($cache, 'test:');
        $cache->set($store->key('default', 'ww-default'), 'stale-token');

        $store->forget('default', 'ww-default');

        $this->assertFalse($cache->has($store->key('default', 'ww-default')));
    }

    public function testForgetOnMissingKeyIsHarmless(): void
    {
        $cache = new InMemoryCache();
        $store = new AccessTokenStore($cache, 'test:');

        $store->forget('default', 'ww-default');

        $this->assertSame([], $cache->all());
    }

    public function testPrefixComesFromConfig(): void
    {
        $cache = new InMemoryCache();

        $store = AccessTokenStore::fromConfig($cache, new Config(['wecom' => ['cache_prefix' => 'aggre:wecom:']]));

        $this->assertStringStartsWith('aggre:wecom:access_token:', $store->key('default', 'ww'));
    }

    public function testBlankPrefixFallsBackToDefault(): void
    {
        $store = AccessTokenStore::fromConfig(new InMemoryCache(), new Config(['wecom' => ['cache_prefix' => ' ']]));

        $this->assertStringStartsWith('wecom:access_token:', $store->key('default', 'ww'));
    }
}
