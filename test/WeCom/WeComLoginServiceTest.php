<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WeCom;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Test\Support\StubLoggerFactory;
use Hyperf\Apex\Test\WeCom\Support\InMemoryCache;
use Hyperf\Apex\WeCom\Cache\AccessTokenStore;
use Hyperf\Apex\WeCom\Config\ConfigAccountResolver;
use Hyperf\Apex\WeCom\DTO\WeComAuthorizeRequestDTO;
use Hyperf\Apex\WeCom\DTO\WeComLoginRequestDTO;
use Hyperf\Apex\WeCom\Exception\WeComException;
use Hyperf\Apex\WeCom\Login\OAuthStateStore;
use Hyperf\Apex\WeCom\Login\WeComLoginService;
use Hyperf\Apex\WeCom\Manager\WeComManager;
use Hyperf\Config\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function json_encode;
use function parse_str;
use function parse_url;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;
use const PHP_URL_QUERY;

/**
 * 一次授权的三种入口与 code 兑换。
 *
 * 钉住四件事：
 * 1. 三个入口（客户端内地址 / 扫码跳转地址 / 面板参数）一次给全、**共用同一个 state**；
 * 2. 面板参数按面板要求**原样**下发 redirect_uri（不做 URLEncode），而两个地址里必须是编码形态；
 * 3. 配置不全时**不签发 state**（否则缓存里会留下一个没人会用的孤儿 state）；
 * 4. code 兑换前先消费 state：state 不成立就不打企微（不打，就不会白白消耗掉那个 code），
 *    外部联系人（只有 openid）不算登录。
 *
 * 业务请求的 HTTP 替身换在 Application 上（token 预置在缓存里，因此不会真去换 token），
 * 这样 state 校验、企业配置、错误分类都走真实代码。
 */
final class WeComLoginServiceTest extends TestCase
{
    /** 带查询串：这样「原样」与「encode」两种形态在断言里一眼能看出区别。 */
    private const REDIRECT_URI = 'https://example.com/wecom/cb?from=login';

    public function testBuildAuthorizeUrlReturnsThreeEntriesSharingOneState(): void
    {
        $cache = new InMemoryCache();
        $config = $this->config();
        $service = $this->service($config, $cache);

        $result = $service->buildAuthorizeUrl(new WeComAuthorizeRequestDTO());

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $result->state);

        $inApp = $this->query($result->inAppAuthorizeUrl);
        $this->assertStringStartsWith('https://open.weixin.qq.com/connect/oauth2/authorize?', $result->inAppAuthorizeUrl);
        $this->assertStringEndsWith('#wechat_redirect', $result->inAppAuthorizeUrl);
        $this->assertSame('ww-test', $inApp['appid']);
        $this->assertSame('code', $inApp['response_type']);
        $this->assertSame('snsapi_base', $inApp['scope']);
        $this->assertSame($result->state, $inApp['state']);
        // 地址里的 redirect_uri 必须是编码形态（parse_str 解回来等于原值）
        $this->assertSame(self::REDIRECT_URI, $inApp['redirect_uri']);

        $qrCode = $this->query($result->qrCodeAuthorizeUrl);
        $this->assertStringStartsWith('https://login.work.weixin.qq.com/wwlogin/sso/login?', $result->qrCodeAuthorizeUrl);
        $this->assertSame('CorpApp', $qrCode['login_type']);
        $this->assertSame('ww-test', $qrCode['appid']);
        $this->assertSame('100001', $qrCode['agentid']);
        $this->assertSame($result->state, $qrCode['state']);

        $panel = $result->loginPanel;
        $this->assertSame('CorpApp', $panel->loginType);
        $this->assertSame('ww-test', $panel->appId);
        $this->assertSame('100001', $panel->agentId);
        // 面板参数按文档要求原样传入，不能是编码后的形态
        $this->assertSame(self::REDIRECT_URI, $panel->redirectUri);
        $this->assertSame($result->state, $panel->state);

        // 三个入口共用的 state 确实签发到了缓存里，且只能用一次
        $states = OAuthStateStore::fromConfig($cache, $config);
        $this->assertSame(300, $cache->ttl('test:oauth_state:default:' . $result->state));
        $this->assertTrue($states->consume('default', $result->state));
        $this->assertFalse($states->consume('default', $result->state));
    }

    public function testRedirectUriOverrideAppliesToAllEntries(): void
    {
        $cache = new InMemoryCache();
        $service = $this->service($this->config(), $cache);

        $result = $service->buildAuthorizeUrl(new WeComAuthorizeRequestDTO(redirectUri: 'https://other.example.com/return'));

        $this->assertSame('https://other.example.com/return', $result->loginPanel->redirectUri);
        $this->assertSame('https://other.example.com/return', $this->query($result->inAppAuthorizeUrl)['redirect_uri']);
        $this->assertSame('https://other.example.com/return', $this->query($result->qrCodeAuthorizeUrl)['redirect_uri']);
    }

    public function testBlankRedirectUriFallsBackToAccountConfig(): void
    {
        $cache = new InMemoryCache();
        $service = $this->service($this->config(), $cache);

        // 可选参数传空串按「未覆盖」处理：前端把空串当默认值是常态
        $result = $service->buildAuthorizeUrl(new WeComAuthorizeRequestDTO(redirectUri: '  '));

        $this->assertSame(self::REDIRECT_URI, $result->loginPanel->redirectUri);
    }

    /**
     * @param array<string, string> $accountOverrides
     */
    #[DataProvider('incompleteAccountProvider')]
    public function testIncompleteConfigFailsWithoutIssuingState(array $accountOverrides): void
    {
        $cache = new InMemoryCache();
        $service = $this->service($this->config($accountOverrides), $cache);

        try {
            $service->buildAuthorizeUrl(new WeComAuthorizeRequestDTO());
            $this->fail('配置不全时应当直接失败，而不是把入口地址发出去。');
        } catch (WeComException $exception) {
            $this->assertSame(ErrorCode::SERVICE_UNAVAILABLE, ErrorCode::tryFrom($exception->getErrorCode()));
            $this->assertStringContainsString('default', $exception->getMessage());
        }

        // 关键：失败发生在签发 state 之前，缓存里不留孤儿 state
        $this->assertSame([], $cache->all());
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function incompleteAccountProvider(): array
    {
        return [
            'agent_id 缺失（拼不出扫码地址与面板参数）' => [['agent_id' => '']],
            'redirect_uri 缺失' => [['redirect_uri' => '']],
            'secret 缺失（回来后换不出 userid）' => [['secret' => '']],
            'corp_id 缺失（等同于企业不存在）' => [['corp_id' => '']],
        ];
    }

    public function testResolveMemberReturnsMember(): void
    {
        $cache = new InMemoryCache();
        $mock = $this->mock([$this->json(['errcode' => 0, 'errmsg' => 'ok', 'userid' => 'zhangsan'])]);
        $service = $this->service($this->config(), $cache, $mock);

        $state = $service->buildAuthorizeUrl(new WeComAuthorizeRequestDTO())->state;
        $member = $service->resolveMember(new WeComLoginRequestDTO(code: 'code-1', state: $state));

        $this->assertSame('default', $member->alias);
        $this->assertSame('zhangsan', $member->userId);
        $this->assertSame(1, $mock->getRequestsCount());
    }

    public function testResolveMemberConsumesStateOnlyOnce(): void
    {
        $cache = new InMemoryCache();
        $mock = $this->mock([$this->json(['errcode' => 0, 'userid' => 'zhangsan'])]);
        $service = $this->service($this->config(), $cache, $mock);
        $state = $service->buildAuthorizeUrl(new WeComAuthorizeRequestDTO())->state;

        $service->resolveMember(new WeComLoginRequestDTO(code: 'code-1', state: $state));

        try {
            $service->resolveMember(new WeComLoginRequestDTO(code: 'code-2', state: $state));
            $this->fail('同一个 state 用第二次应当失败。');
        } catch (WeComException $exception) {
            $this->assertSame(ErrorCode::UNAUTHENTICATED, ErrorCode::tryFrom($exception->getErrorCode()));
        }

        // 重放被挡在第一步：第二次没有打企微，也就没有消耗任何 code
        $this->assertSame(1, $mock->getRequestsCount());
    }

    public function testResolveMemberRejectsUnknownStateWithoutCallingWeCom(): void
    {
        $cache = new InMemoryCache();
        $mock = $this->mock([]);
        $service = $this->service($this->config(), $cache, $mock);

        try {
            $service->resolveMember(new WeComLoginRequestDTO(code: 'code-1', state: 'not-issued'));
            $this->fail('未知 state 应当直接失败。');
        } catch (WeComException $exception) {
            $this->assertSame(ErrorCode::UNAUTHENTICATED, ErrorCode::tryFrom($exception->getErrorCode()));
            $this->assertSame(401, $exception->getHttpStatus()->value);
        }

        $this->assertSame(0, $mock->getRequestsCount());
    }

    public function testResolveMemberRejectsExternalContact(): void
    {
        $cache = new InMemoryCache();
        // 外部联系人：errcode = 0，但只有 openid，没有 userid
        $mock = $this->mock([$this->json(['errcode' => 0, 'errmsg' => 'ok', 'openid' => 'o-external'])]);
        $service = $this->service($this->config(), $cache, $mock);

        $state = $service->buildAuthorizeUrl(new WeComAuthorizeRequestDTO())->state;

        try {
            $service->resolveMember(new WeComLoginRequestDTO(code: 'code-1', state: $state));
            $this->fail('外部联系人不能当成本企业成员放行。');
        } catch (WeComException $exception) {
            $this->assertSame(ErrorCode::UNAUTHENTICATED, ErrorCode::tryFrom($exception->getErrorCode()));
            // 不是企微报错，是「这个人不成立」：原始错误码为 0
            $this->assertSame(0, $exception->getWeComErrorCode());
        }
    }

    /**
     * @param array<string, string> $accountOverrides
     */
    private function config(array $accountOverrides = []): Config
    {
        return new Config([
            'wecom' => [
                'default' => 'default',
                'accounts' => [
                    'default' => array_merge([
                        'corp_id' => 'ww-test',
                        'agent_id' => '100001',
                        'secret' => 'secret',
                        'redirect_uri' => self::REDIRECT_URI,
                    ], $accountOverrides),
                ],
                'cache_prefix' => 'test:',
                'state_ttl' => 300,
            ],
        ]);
    }

    private function service(Config $config, InMemoryCache $cache, ?HttpClientInterface $mock = null): WeComLoginService
    {
        $manager = new WeComManager(
            new ConfigAccountResolver($config),
            AccessTokenStore::fromConfig($cache, $config),
            $cache,
            $config,
            StubLoggerFactory::create('outbound'),
        );

        if ($mock !== null) {
            /*
             * 换掉底层 HTTP 客户端，必须在任何 getClient() 之前；token 预置在缓存里，
             * 因此重试逻辑之外的路径不会真的去换 token。
             */
            $this->withToken($cache, 'ww-test');
            $manager->application()->setHttpClient($mock);
        }

        return new WeComLoginService($manager, OAuthStateStore::fromConfig($cache, $config));
    }

    private function withToken(InMemoryCache $cache, string $corpId): void
    {
        $cache->set((new AccessTokenStore($cache, 'test:'))->key('default', $corpId), 'cached-token');
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function mock(array $responses): MockHttpClient
    {
        return new MockHttpClient($responses);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload): MockResponse
    {
        return new MockResponse(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json; charset=utf-8']],
        );
    }

    /**
     * @return array<string, string>
     */
    private function query(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $parsed);

        /* @var array<string, string> $parsed */
        return $parsed;
    }
}
