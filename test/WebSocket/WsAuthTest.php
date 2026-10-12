<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Security\Principal;
use Hyperf\Apex\Test\WebSocket\Support\WsTestConfig;
use Hyperf\Apex\WebSocket\Auth\WsBearerToken;
use Hyperf\Apex\WebSocket\Auth\WsHandshakeContext;
use Hyperf\Apex\WebSocket\Auth\WsIntegrationHandshakeMiddleware;
use Hyperf\Context\Context as CoContext;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Server\Response;
use Hyperf\WebSocketServer\Context as ConnectionContext;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 握手鉴权。
 *
 * 两件事必须成立：token 的来源（浏览器 WebSocket API 不能自定义请求头，只能走 query 或
 * Sec-WebSocket-Protocol 子协议），以及**校验失败发生在 onOpen 之前** —— 否则会留下
 * 「连接已建立但没有身份」的中间态。身份中转（WsHandshakeContext）只服务于这一跳，
 * 组装完会话就必须清掉。
 */
final class WsAuthTest extends TestCase
{
    private const FD = 7002;

    protected function tearDown(): void
    {
        WsHandshakeContext::clear();
        ConnectionContext::release(self::FD);
    }

    public function testTokenPrefersTheQueryParameterAndTrimsIt(): void
    {
        $request = (new Request('GET', '/ws/hub'))->withQueryParams(['token' => '  abc  ']);

        $this->assertSame('abc', WsBearerToken::token($request, 'token'));
    }

    public function testTokenFallsBackToTheSubProtocol(): void
    {
        // token 也可能放在 Sec-WebSocket-Protocol 里，取第一个子协议。
        $request = (new Request('GET', '/ws/hub'))->withHeader('Sec-WebSocket-Protocol', 'secret-one, secret-two');

        $this->assertSame('secret-one', WsBearerToken::token($request, 'token'));
    }

    public function testTokenIsEmptyWhenNothingIsProvided(): void
    {
        $this->assertSame('', WsBearerToken::token(new Request('GET', '/ws/hub'), 'token'));
        $this->assertSame('', WsBearerToken::token((new Request('GET', '/ws/hub'))->withQueryParams(['token' => '  ']), 'token'));
    }

    public function testIntegrationMiddlewareAcceptsTheConfiguredToken(): void
    {
        $response = $this->middleware('secret')->process(
            (new Request('GET', '/ws/hub'))->withQueryParams(['token' => 'secret']),
            $this->handler(),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIntegrationMiddlewareRejectsAWrongToken(): void
    {
        try {
            $this->middleware('secret')->process(
                (new Request('GET', '/ws/hub'))->withQueryParams(['token' => 'nope']),
                $this->handler(),
            );
            $this->fail('token 不匹配时必须抛未认证异常。');
        } catch (BusinessException $exception) {
            $this->assertSame(ErrorCode::UNAUTHENTICATED->value, $exception->getErrorCode());
        }
    }

    public function testIntegrationMiddlewareTreatsMissingConfigurationAsUnauthenticated(): void
    {
        // 未配置集成 token 时同样按未认证处理，不把「配置缺失」暴露给客户端；
        // 真正的配置缺失由 EnvironmentConfigCheckListener 在启动期拦下。
        try {
            $this->middleware('')->process(
                (new Request('GET', '/ws/hub'))->withQueryParams(['token' => '']),
                $this->handler(),
            );
            $this->fail('未配置集成 token 时必须拒绝。');
        } catch (BusinessException $exception) {
            $this->assertSame(ErrorCode::UNAUTHENTICATED->value, $exception->getErrorCode());
        }
    }

    public function testHandshakePrincipalIsReadableUntilItIsCleared(): void
    {
        CoContext::set(ConnectionContext::FD, self::FD);
        $principal = new Principal(userId: 7, userType: 'hub', username: 'hub-7', sessionId: 's-7');

        $this->assertNull(WsHandshakeContext::principal());

        WsHandshakeContext::setPrincipal($principal);
        $this->assertSame($principal, WsHandshakeContext::principal());

        // 入口在组装完会话后调用：连接的身份此后由 WsSession 承载。
        WsHandshakeContext::clear();
        $this->assertNull(WsHandshakeContext::principal());
    }

    private function middleware(string $token): WsIntegrationHandshakeMiddleware
    {
        return new WsIntegrationHandshakeMiddleware(
            WsTestConfig::make([], ['integration' => ['integration_token' => $token]]),
        );
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };
    }
}
