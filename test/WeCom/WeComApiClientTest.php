<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WeCom;

use EasyWeChat\Kernel\HttpClient\AccessTokenAwareClient;
use EasyWeChat\Work\AccessToken as WorkAccessToken;
use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Test\WeCom\Support\InMemoryCache;
use Hyperf\Apex\WeCom\Api\WeComApiClient;
use Hyperf\Apex\WeCom\Cache\AccessTokenStore;
use Hyperf\Apex\WeCom\DTO\WeComAccountDTO;
use Hyperf\Apex\WeCom\Exception\WeComException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;
use const PHP_URL_QUERY;

/**
 * 企微接口出口。
 *
 * 三条最容易做错的地方逐条钉住：
 * 1. 失败在响应体的 errcode 里，HTTP 200 / 500 都不说明调用成功与否 —— 判定必须读 errcode；
 * 2. 40014 / 42001 必须「清缓存 + 重发一次」，且重发用的必须是**新** token；
 * 3. 只重发一次，第二次同样失败就直接上报，不能无限重试。
 *
 * 用 Symfony 的 MockHttpClient 同时充当 token 客户端与业务客户端：token 的刷新过程
 * （GET cgi-bin/gettoken → 写缓存 → 下一次请求带上新 token）因此可以被端到端断言。
 */
final class WeComApiClientTest extends TestCase
{
    public function testSuccessReturnsDecodedBody(): void
    {
        $requests = [];
        $client = $this->apiClient($this->recordingMock($requests, [
            $this->json(['errcode' => 0, 'errmsg' => 'ok', 'userid' => 'zhangsan']),
        ]));

        $data = $client->get('cgi-bin/auth/getuserinfo', ['code' => 'code-1']);

        $this->assertSame(0, $data['errcode']);
        $this->assertSame('zhangsan', $data['userid']);
        $this->assertSame('GET', $requests[0]['method']);
        $this->assertStringContainsString('cgi-bin/auth/getuserinfo', $requests[0]['url']);
        $this->assertSame('code-1', $this->queryOf($requests[0])['code']);
        // access_token 由客户端自动带上，调用方不需要（也不该）自己拼
        $this->assertSame('cached-token', $this->queryOf($requests[0])['access_token']);
    }

    public function testPostSendsJsonBody(): void
    {
        $requests = [];
        $client = $this->apiClient($this->recordingMock($requests, [$this->json(['errcode' => 0])]));

        $client->post('cgi-bin/message/send', ['touser' => 'zhangsan', 'msgtype' => 'text']);

        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame(
            ['touser' => 'zhangsan', 'msgtype' => 'text'],
            json_decode((string) ($requests[0]['options']['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testAccessTokenInvalidIsRetriedWithAFreshToken(): void
    {
        $cache = new InMemoryCache();
        $account = $this->account();
        $store = new AccessTokenStore($cache, 'test:');
        $cache->set($store->key($account->alias, $account->corpId), 'stale-token');

        $requests = [];
        $client = $this->apiClient(
            $this->recordingMock($requests, [
                $this->json(['errcode' => 40014, 'errmsg' => 'invalid access_token']),
                $this->json(['errcode' => 0, 'access_token' => 'fresh-token', 'expires_in' => 7200]),
                $this->json(['errcode' => 0, 'errmsg' => 'ok', 'userid' => 'zhangsan']),
            ]),
            $cache,
        );

        $data = $client->get('cgi-bin/auth/getuserinfo', ['code' => 'code-1']);

        $this->assertSame('zhangsan', $data['userid']);
        $this->assertCount(3, $requests);
        // 第一次用缓存里的旧 token；中间去换了一个；重发用的是新 token
        $this->assertSame('stale-token', $this->queryOf($requests[0])['access_token']);
        $this->assertStringContainsString('cgi-bin/gettoken', $requests[1]['url']);
        $this->assertSame('fresh-token', $this->queryOf($requests[2])['access_token']);
        // 新 token 也写回了 manager 会去失效的那个键
        $this->assertSame('fresh-token', $cache->get($store->key($account->alias, $account->corpId)));
    }

    public function testRepeatedAccessTokenFailureIsReportedAsUnavailable(): void
    {
        $cache = new InMemoryCache();
        $account = $this->account();
        $cache->set((new AccessTokenStore($cache, 'test:'))->key($account->alias, $account->corpId), 'stale-token');

        $requests = [];
        $client = $this->apiClient(
            $this->recordingMock($requests, [
                $this->json(['errcode' => 42001, 'errmsg' => 'access_token expired']),
                $this->json(['errcode' => 0, 'access_token' => 'fresh-token', 'expires_in' => 7200]),
                $this->json(['errcode' => 42001, 'errmsg' => 'access_token expired']),
            ]),
            $cache,
        );

        try {
            $client->get('cgi-bin/auth/getuserinfo', ['code' => 'code-1']);
            $this->fail('第二次失败后应当直接抛出，而不是继续重试。');
        } catch (WeComException $exception) {
            $this->assertSame(42001, $exception->getWeComErrorCode());
            $this->assertSame(ErrorCode::SERVICE_UNAVAILABLE, ErrorCode::tryFrom($exception->getErrorCode()));
        }

        $this->assertCount(3, $requests);
    }

    public function testInvalidCodeIsUnauthenticated(): void
    {
        $requests = [];
        $client = $this->apiClient($this->recordingMock($requests, [
            $this->json(['errcode' => 40029, 'errmsg' => 'invalid code']),
        ]));

        try {
            $client->get('cgi-bin/auth/getuserinfo', ['code' => 'used-code']);
            $this->fail('授权码失效应当抛未认证。');
        } catch (WeComException $exception) {
            $this->assertSame(40029, $exception->getWeComErrorCode());
            $this->assertSame(ErrorCode::UNAUTHENTICATED, ErrorCode::tryFrom($exception->getErrorCode()));
            $this->assertSame(401, $exception->getHttpStatus()->value);
            $this->assertSame(['errcode' => 40029], $exception->getData());
        }

        // code 无效与 token 无关：不该白白多发一次请求
        $this->assertCount(1, $requests);
    }

    public function testHttpErrorStatusIsStillJudgedByErrCode(): void
    {
        $requests = [];
        $client = $this->apiClient($this->recordingMock($requests, [
            new MockResponse(
                json_encode(['errcode' => 40003, 'errmsg' => 'invalid userid'], JSON_UNESCAPED_UNICODE),
                ['http_code' => 500, 'response_headers' => ['content-type' => 'application/json']],
            ),
        ]));

        try {
            $client->get('cgi-bin/user/get', ['userid' => 'nobody']);
            $this->fail('HTTP 5xx 也应当按失败处理。');
        } catch (WeComException $exception) {
            $this->assertSame(40003, $exception->getWeComErrorCode());
            $this->assertSame(ErrorCode::SERVICE_UNAVAILABLE, ErrorCode::tryFrom($exception->getErrorCode()));
        }
    }

    public function testEmptyBodyIsReportedAsUnavailable(): void
    {
        $requests = [];
        $client = $this->apiClient($this->recordingMock($requests, [new MockResponse('')]));

        try {
            $client->get('cgi-bin/user/get', ['userid' => 'nobody']);
            $this->fail('响应体为空应当按服务不可用处理。');
        } catch (WeComException $exception) {
            $this->assertSame(0, $exception->getWeComErrorCode());
            $this->assertSame(ErrorCode::SERVICE_UNAVAILABLE, ErrorCode::tryFrom($exception->getErrorCode()));
        }
    }

    public function testTransportFailureIsReportedAsUnavailable(): void
    {
        $mock = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('连接超时');
        });

        try {
            $this->apiClient($mock)->get('cgi-bin/user/get', ['userid' => 'zhangsan']);
            $this->fail('传输失败应当按服务不可用处理。');
        } catch (WeComException $exception) {
            $this->assertSame(0, $exception->getWeComErrorCode());
            $this->assertSame(ErrorCode::SERVICE_UNAVAILABLE, ErrorCode::tryFrom($exception->getErrorCode()));
        }
    }

    private function apiClient(
        HttpClientInterface $client,
        ?InMemoryCache $cache = null,
        ?WeComAccountDTO $account = null,
    ): WeComApiClient {
        $cache ??= new InMemoryCache();
        $account ??= $this->account();
        $store = new AccessTokenStore($cache, 'test:');
        $key = $store->key($account->alias, $account->corpId);

        /*
         * 预置一个 token，让业务请求自己就是第一个请求（否则 EasyWeChat 会先发一次 gettoken，
         * 把 mock 的响应队列吃掉一条，后面所有断言都会错位）。
         * 考察自愈的用例会先把 stale-token 放进缓存，这里就不覆盖。
         */
        if (! $cache->has($key)) {
            $cache->set($key, 'cached-token');
        }

        return new WeComApiClient(
            new AccessTokenAwareClient(
                client: $client,
                accessToken: new WorkAccessToken(
                    corpId: $account->corpId,
                    secret: $account->secret,
                    key: $store->key($account->alias, $account->corpId),
                    cache: $cache,
                    httpClient: $client,
                ),
            ),
            $store,
            $account,
            new NullLogger(),
        );
    }

    private function account(): WeComAccountDTO
    {
        return new WeComAccountDTO(alias: 'default', corpId: 'ww-test', agentId: '100001', secret: 'secret');
    }

    /**
     * @param list<array{method: string, url: string, options: array<string, mixed>}> $requests 按引用回填
     * @param list<MockResponse> $responses
     */
    private function recordingMock(array &$requests, array $responses): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            $next = array_shift($responses);
            if (! $next instanceof MockResponse) {
                throw new TransportException('MockHttpClient 的响应队列已耗尽：实际请求次数超出预期。');
            }

            return $next;
        });
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
     * @param array{method: string, url: string, options: array<string, mixed>} $request
     * @return array<string, string>
     */
    private function queryOf(array $request): array
    {
        // query 可能被拼进 URL，也可能仍留在 options 里（取决于 Symfony 的 prepareRequest），两者都看。
        $query = parse_url($request['url'], PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $parsed);

        /** @var array<string, string> $fromOptions */
        $fromOptions = $request['options']['query'] ?? [];

        /* @var array<string, string> $merged */
        return array_merge($parsed, $fromOptions);
    }
}
