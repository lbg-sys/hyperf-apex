<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\Middleware;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Http\ClientIpResolver;
use Hyperf\Apex\Http\HandlerMethodResolver;
use Hyperf\Apex\Http\IpRuleMatcher;
use Hyperf\Apex\RateLimit\RateLimiter;
use Hyperf\Apex\RateLimit\RateLimitIdentityResolverRegistry;
use Hyperf\Apex\RateLimit\RateLimitResult;
use Hyperf\Apex\RateLimit\RateLimitRuleReader;
use Hyperf\Apex\RateLimit\RateLimitWhitelist;
use Hyperf\Context\ResponseContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Logger\LoggerFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * 按 #[RateLimit] 声明执行限流。
 *
 * 挂载位置有约束：必须在 CorsMiddleware 之后（否则 429 响应没有 CORS 头，浏览器端
 * 只会看到一个网络错误），必须在提供身份上下文的中间件之后（否则取不到标识，规则
 * 会被静默跳过）。顺序错了不会报错，只会静默产生错误行为。
 */
final class RateLimitMiddleware extends AbstractMiddleware
{
    private const MESSAGE = '请求过于频繁，请稍后重试';

    private readonly LoggerInterface $logger;

    /** @var null|RateLimitWhitelist 缓存：白名单由纯配置派生，运行期不变 */
    private ?RateLimitWhitelist $whitelist = null;

    public function __construct(
        LoggerFactory $loggerFactory,
        private readonly ConfigInterface $config,
        private readonly HandlerMethodResolver $handlerMethods,
        private readonly RateLimitRuleReader $rules,
        private readonly RateLimitIdentityResolverRegistry $identities,
        private readonly RateLimiter $limiter,
        private readonly ClientIpResolver $clientIp,
        private readonly IpRuleMatcher $ipRules,
    ) {
        $this->logger = $loggerFactory->get('rate_limit', 'shared');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (! (bool) $this->config->get('rate_limit.enabled', true)) {
            return $handler->handle($request);
        }

        // 路由未匹配时没有可依据的声明，直接放行。
        $method = $this->handlerMethods->resolve($request);
        if ($method === null) {
            return $handler->handle($request);
        }

        $rules = $this->rules->read($method);
        if ($rules === [] || $this->isWhitelisted($request)) {
            return $handler->handle($request);
        }

        $narrowest = null;
        $rejected = null;
        foreach ($rules as $rule) {
            $identity = $this->identities->resolve($rule->scope, $request);
            if ($identity === null || $identity === '') {
                // 宁可不限流，也不能错限流：退化成共享桶等于让所有主体共用一个配额。
                $this->logger->warning('限流维度缺少解析器或主体标识，已跳过该规则。', [
                    'rule' => $rule->name,
                    'scope' => $rule->scope,
                ]);
                continue;
            }

            $result = $this->limiter->attempt($identity, $rule);
            if ($narrowest === null || $result->remaining < $narrowest->remaining) {
                $narrowest = $result;
            }

            if (! $result->allowed) {
                $rejected = $rule;

                break;
            }
        }

        if ($narrowest !== null && $rejected !== null) {
            $this->writeHeaders($narrowest);

            throw new BusinessException(ErrorCode::TOO_MANY_REQUESTS, $rejected->message ?? self::MESSAGE);
        }

        $response = $handler->handle($request);

        return $narrowest === null ? $response : $this->withHeaders($response, $narrowest);
    }

    private function isWhitelisted(ServerRequestInterface $request): bool
    {
        return $this->whitelist()->contains($this->clientIp->resolve($request));
    }

    /**
     * 白名单由纯配置派生、运行期不变，而 isWhitelisted() 每个带限流规则的请求都会走一遍，
     * 所以缓存一次，避免每回重读配置、重建数组。
     */
    private function whitelist(): RateLimitWhitelist
    {
        if ($this->whitelist !== null) {
            return $this->whitelist;
        }

        $configured = $this->config->get('rate_limit.whitelist_ips', []);
        /** @var list<string> $rules */
        $rules = is_array($configured)
            ? array_values(array_filter($configured, static fn (mixed $rule): bool => is_string($rule) && $rule !== ''))
            : [];

        return $this->whitelist = new RateLimitWhitelist($this->ipRules, $rules);
    }

    /**
     * 异常会冒泡到 AppExceptionHandler，由它基于响应上下文里的对象重建响应，中间件里
     * 对局部变量设置的头会全部丢失，所以这里必须写进 ResponseContext。
     */
    private function writeHeaders(RateLimitResult $result): void
    {
        $response = ResponseContext::getOrNull();
        if ($response === null) {
            return;
        }

        foreach ($result->headers() as $name => $value) {
            $response->setHeader($name, $value);
        }
    }

    private function withHeaders(ResponseInterface $response, RateLimitResult $result): ResponseInterface
    {
        foreach ($result->headers() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
