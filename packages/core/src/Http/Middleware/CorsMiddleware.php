<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\Middleware;

use Hyperf\Apex\Support\StringList;
use Hyperf\Context\ResponseContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpMessage\Server\Response as ServerResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swow\Psr7\Message\ResponsePlusInterface;
use Throwable;

/**
 * 跨域响应头。
 *
 * 来源走 `cors.allowed_origins` 白名单，不再无条件写 `*`：`*` 让任意站点都能读到本服务的响应，
 * 只在确实要对外开放时才应该是配置的默认值（默认保持 `['*']` 是为了不把已有前端的跨域请求
 * 在升级时一次掐断，生产应收窄到具体来源）。
 *
 * 允许的请求头同样取自配置，不回显 `Access-Control-Request-Headers`：
 * 回显等于让请求方决定我们响应头的内容。
 */
final class CorsMiddleware extends AbstractMiddleware
{
    /** 通配值：来源与请求头都用它表示「不限定」。 */
    private const ANY = '*';

    public function __construct(private readonly ConfigInterface $config)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            $response = (new ServerResponse())->withStatus(204);
            $this->addCorsHeaders($response, $request);

            return $response;
        }

        try {
            $response = $handler->handle($request);
        } catch (Throwable $throwable) {
            // 下游异常会冒泡到异常处理器，由它基于响应上下文里的对象重建响应，中间件拿不到这个响应。
            // 这里先把跨域头写回上下文，保证 401 / 403 / 404 / 500 等错误响应同样带 CORS 头。
            $contextResponse = ResponseContext::getOrNull();
            if ($contextResponse !== null) {
                $this->addCorsHeaders($contextResponse, $request);
            }

            throw $throwable;
        }

        if ($response instanceof ResponsePlusInterface) {
            $this->addCorsHeaders($response, $request);
        }

        return $response;
    }

    private function addCorsHeaders(ResponsePlusInterface $response, ServerRequestInterface $request): void
    {
        $allowedOrigins = StringList::of($this->config->get('cors.allowed_origins', [self::ANY]));
        if ($allowedOrigins === []) {
            // 显式的空名单 = 不下发任何跨域头，浏览器按同源策略自行拒绝。
            return;
        }

        $allowOrigin = $this->resolveAllowOrigin($allowedOrigins, trim($request->getHeaderLine('Origin')));
        if ($allowOrigin === null) {
            return;
        }

        $response
            ->setHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->setHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders()))
            ->setHeader('Access-Control-Max-Age', '86400');

        if ($allowOrigin !== self::ANY) {
            // 响应随请求的 Origin 变化，不声明 Vary 的话，中间代理会把一个来源的响应喂给另一个来源。
            // 用 addHeader 而不是 setHeader：Vary 可能已经带着别的值（如 Accept-Encoding）。
            $response->addHeader('Vary', 'Origin');
        }
    }

    /**
     * 命中的 Allow-Origin 值；未命中返回 null。
     *
     * 名单里没有 `*` 时必须精确匹配 Origin：带 `*` 时代码本来就不该读 Cookie，
     * 收紧来源才有意义，所以这里不做子域通配。
     *
     * @param list<string> $allowedOrigins
     */
    private function resolveAllowOrigin(array $allowedOrigins, string $origin): ?string
    {
        if (in_array(self::ANY, $allowedOrigins, true)) {
            return self::ANY;
        }

        return $origin !== '' && in_array($origin, $allowedOrigins, true) ? $origin : null;
    }

    /**
     * @return list<string>
     */
    private function allowedHeaders(): array
    {
        // 空值退回 `*`：放开请求头不造成信息泄漏（只是允许客户端多带几个头），没有收窄的必要。
        return StringList::of($this->config->get('cors.allowed_headers', [])) ?: [self::ANY];
    }
}
