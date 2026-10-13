<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WeCom;

use DateInterval;
use Hyperf\Apex\Test\WeCom\Support\InMemoryCache;
use Hyperf\Apex\WeCom\Cache\ExpiringCache;
use PHPUnit\Framework\TestCase;

/**
 * access_token 的缓存必须比企微给的 expires_in 短一截。
 *
 * 这里钉住两件事：TTL 确实被减了，以及「减到没有」时不会写出一个比 expires_in 还长的缓存窗口
 * （那会让服务端拿着一个已知过期的 token 去发请求）。
 */
final class ExpiringCacheTest extends TestCase
{
    public function testTtlIsShortenedByAheadSeconds(): void
    {
        $cache = new InMemoryCache();
        $decorator = new ExpiringCache($cache, 300);

        $decorator->set('token', 'abc', 7200);

        $this->assertSame('abc', $cache->get('token'));
        $this->assertSame(6900, $cache->ttl('token'));
    }

    public function testShortTtlKeepsAMinimalWindow(): void
    {
        $cache = new InMemoryCache();

        new ExpiringCache($cache, 300)->set('token', 'abc', 200);

        $this->assertSame(60, $cache->ttl('token'));
    }

    public function testNullTtlIsPassedThrough(): void
    {
        $cache = new InMemoryCache();

        new ExpiringCache($cache, 300)->set('token', 'abc');

        $this->assertSame('abc', $cache->get('token'));
        $this->assertNull($cache->ttl('token'));
    }

    public function testZeroTtlIsNotStretched(): void
    {
        $cache = new InMemoryCache();

        new ExpiringCache($cache, 0)->set('token', 'abc', 0);

        $this->assertSame(0, $cache->ttl('token'));
    }

    public function testDateIntervalTtlIsConverted(): void
    {
        $cache = new InMemoryCache();

        new ExpiringCache($cache, 300)->set('token', 'abc', new DateInterval('PT10M'));

        $this->assertSame(300, $cache->ttl('token'));
    }

    public function testReadsAndDeletesArePassedThrough(): void
    {
        $cache = new InMemoryCache();
        $decorator = new ExpiringCache($cache, 300);
        $cache->set('token', 'abc', 100);

        $this->assertSame('abc', $decorator->get('token'));
        $this->assertSame('fallback', $decorator->get('missing', 'fallback'));
        $this->assertTrue($decorator->has('token'));
        $this->assertTrue($decorator->delete('token'));
        $this->assertFalse($decorator->has('token'));
    }
}
