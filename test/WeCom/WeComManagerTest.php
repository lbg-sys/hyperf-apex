<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WeCom;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Test\Support\StubLoggerFactory;
use Hyperf\Apex\Test\WeCom\Support\InMemoryCache;
use Hyperf\Apex\WeCom\Cache\AccessTokenStore;
use Hyperf\Apex\WeCom\Config\ConfigAccountResolver;
use Hyperf\Apex\WeCom\Exception\WeComException;
use Hyperf\Apex\WeCom\Manager\WeComManager;
use Hyperf\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * 多企业路由与「客户端按 alias 复用」。
 *
 * 最要紧的一条是**缓存键的一致性**：manager 把 token 存到哪个键上、forget 去删哪个键、
 * EasyWeChat 自己读哪个键，必须是同一个。这三者只要有一处对不上，preload 一个 token 就读不到、
 * forget 也就删不掉 —— 表现是「token 永远换不掉」这种极难查的问题。
 * 因此这里用「预置 token 后 accessToken() 不发请求」来钉住它（真的发请求会因 MockHttpClient 缺省
 * 无响应而失败，或者直接打网络）。
 */
final class WeComManagerTest extends TestCase
{
    public function testAliasRoutingAndDefaultAlias(): void
    {
        [$manager] = $this->manager(new InMemoryCache(), [
            'default' => $this->accountConfig('ww-default'),
            'sales' => $this->accountConfig('ww-sales'),
        ]);

        $this->assertSame('default', $manager->defaultAlias());
        $this->assertSame('default', $manager->account()->alias);
        $this->assertSame('ww-sales', $manager->account('sales')->corpId);
        // 可选参数传空串按「未指定」处理：前端把空串当默认值是常态
        $this->assertSame('ww-default', $manager->account('')->corpId);
        $this->assertSame('ww-default', $manager->account('  ')->corpId);
    }

    public function testUnknownAliasIsServiceUnavailable(): void
    {
        [$manager] = $this->manager(new InMemoryCache(), ['default' => $this->accountConfig('ww-default')]);

        try {
            $manager->application('sales');
            $this->fail('未配置的企业应当直接失败，而不是拿空配置去发请求。');
        } catch (WeComException $exception) {
            $this->assertSame(ErrorCode::SERVICE_UNAVAILABLE, ErrorCode::tryFrom($exception->getErrorCode()));
            $this->assertStringContainsString('sales', $exception->getMessage());
        }
    }

    public function testAccountWithoutSecretIsRefused(): void
    {
        [$manager] = $this->manager(new InMemoryCache(), ['default' => $this->accountConfig('ww-default', '')]);

        $this->assertFalse($manager->account()->canCallApi());

        try {
            $manager->application();
            $this->fail('缺 secret 的配置应当直接失败。');
        } catch (WeComException $exception) {
            $this->assertSame(ErrorCode::SERVICE_UNAVAILABLE, ErrorCode::tryFrom($exception->getErrorCode()));
        }
    }

    public function testApplicationAndApiClientAreSharedPerAlias(): void
    {
        [$manager] = $this->manager(new InMemoryCache(), [
            'default' => $this->accountConfig('ww-default'),
            'sales' => $this->accountConfig('ww-sales'),
        ]);

        // 同一个 alias 复用同一个实例（连接池与 token 缓存的复用都建立在这上面）
        $this->assertSame($manager->application(), $manager->application('default'));
        $this->assertSame($manager->api('sales'), $manager->api('sales'));
        // 不同企业必须各是各的，否则一个企业的 token 会被拿到另一个企业去用
        $this->assertNotSame($manager->application('default'), $manager->application('sales'));
    }

    public function testAccessTokenIsReadThroughTheSharedCache(): void
    {
        $cache = new InMemoryCache();
        [$manager, $store] = $this->manager($cache, ['default' => $this->accountConfig('ww-default')]);

        $cache->set($store->key('default', 'ww-default'), 'cached-token');

        $this->assertSame('cached-token', $manager->accessToken());
    }

    public function testForgetAccessTokenClearsTheSharedKey(): void
    {
        $cache = new InMemoryCache();
        [$manager, $store] = $this->manager($cache, ['default' => $this->accountConfig('ww-default')]);
        $cache->set($store->key('default', 'ww-default'), 'cached-token');

        $manager->forgetAccessToken();

        $this->assertFalse($cache->has($store->key('default', 'ww-default')));
    }

    public function testTokensAreScopedByAlias(): void
    {
        $cache = new InMemoryCache();
        [$manager, $store] = $this->manager($cache, [
            'default' => $this->accountConfig('ww-default'),
            'sales' => $this->accountConfig('ww-sales'),
        ]);

        // 只给 sales 预置 token：default 读不到它，说明两个企业用的是不同的键
        $cache->set($store->key('sales', 'ww-sales'), 'sales-token');

        $this->assertSame('sales-token', $manager->accessToken('sales'));
        $this->assertFalse($cache->has($store->key('default', 'ww-default')));
    }

    /**
     * @param array<string, mixed> $accounts
     * @return array{0: WeComManager, 1: AccessTokenStore}
     */
    private function manager(InMemoryCache $cache, array $accounts): array
    {
        $config = new Config([
            'wecom' => [
                'default' => 'default',
                'accounts' => $accounts,
                'cache_prefix' => 'test:',
                'timeout' => 3,
            ],
        ]);
        $store = AccessTokenStore::fromConfig($cache, $config);

        return [
            new WeComManager(
                new ConfigAccountResolver($config),
                $store,
                $cache,
                $config,
                StubLoggerFactory::create('outbound'),
            ),
            $store,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function accountConfig(string $corpId, string $secret = 'secret'): array
    {
        return [
            'corp_id' => $corpId,
            'agent_id' => '100001',
            'secret' => $secret,
            'redirect_uri' => 'https://example.com/wecom/callback',
        ];
    }
}
