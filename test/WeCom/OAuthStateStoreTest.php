<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WeCom;

use Hyperf\Apex\Test\WeCom\Support\InMemoryCache;
use Hyperf\Apex\WeCom\Login\OAuthStateStore;
use Hyperf\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * 授权 state 的一次性语义。
 *
 * state 唯一要防的是「拿到别人的 code 就能登录」，所以「同一个 state 只能用一次」
 * 与「空值一律不通过」这两条必须成立；跨企业的键也必须分开，否则一个企业签发的 state
 * 能被另一个企业用掉。
 */
final class OAuthStateStoreTest extends TestCase
{
    public function testIssueWritesRandomStateWithTtl(): void
    {
        $cache = new InMemoryCache();
        $store = new OAuthStateStore($cache, 'test:', 300);

        $state = $store->issue('default');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $state);
        $this->assertSame('1', $cache->get('test:oauth_state:default:' . $state));
        $this->assertSame(300, $cache->ttl('test:oauth_state:default:' . $state));
    }

    public function testStateIsConsumedExactlyOnce(): void
    {
        $cache = new InMemoryCache();
        $store = new OAuthStateStore($cache, 'test:', 300);
        $state = $store->issue('default');

        $this->assertTrue($store->consume('default', $state));
        $this->assertFalse($store->consume('default', $state));
    }

    public function testUnknownAndBlankStateFailClosed(): void
    {
        $store = new OAuthStateStore(new InMemoryCache(), 'test:', 300);

        $this->assertFalse($store->consume('default', 'not-issued'));
        $this->assertFalse($store->consume('default', ''));
        $this->assertFalse($store->consume('default', '  '));
    }

    public function testStateIsScopedByAlias(): void
    {
        $store = new OAuthStateStore(new InMemoryCache(), 'test:', 300);
        $state = $store->issue('default');

        $this->assertFalse($store->consume('sales', $state));
        // 试错的那次不能把 default 的 state 弄丢
        $this->assertTrue($store->consume('default', $state));
    }

    public function testTtlHasALowerBound(): void
    {
        $cache = new InMemoryCache();

        $state = new OAuthStateStore($cache, 'test:', 5)->issue('default');

        // 有效期短到走不完一次授权，只会变成「用户总说登录失败」，因此抬到 60 秒。
        $this->assertSame(60, $cache->ttl('test:oauth_state:default:' . $state));
    }

    public function testTtlAndPrefixComeFromConfig(): void
    {
        $cache = new InMemoryCache();

        $state = OAuthStateStore::fromConfig($cache, new Config([
            'wecom' => ['cache_prefix' => 'aggre:wecom:', 'state_ttl' => 120],
        ]))->issue('default');

        $this->assertSame(120, $cache->ttl('aggre:wecom:oauth_state:default:' . $state));
    }
}
