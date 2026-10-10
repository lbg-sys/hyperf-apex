<?php

declare(strict_types=1);

namespace Hyperf\Apex\RateLimit\Resolver;

use Hyperf\Apex\Http\ClientIpResolver;
use Hyperf\Apex\RateLimit\Contract\RateLimitIdentityResolverInterface;
use Hyperf\Apex\RateLimit\RateLimitScopes;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 按客户端 IP 限流。
 *
 * 标识必须走 ClientIpResolver：直接读 X-Forwarded-For 等于让客户端自选限流桶，
 * 而该类已按不可伪造的 remote_addr 建立了信任边界。
 */
final class ClientIpIdentityResolver implements RateLimitIdentityResolverInterface
{
    public function __construct(private readonly ClientIpResolver $clientIp)
    {
    }

    public function scope(): string
    {
        return RateLimitScopes::IP;
    }

    public function resolve(ServerRequestInterface $request): ?string
    {
        return $this->clientIp->resolve($request);
    }
}
