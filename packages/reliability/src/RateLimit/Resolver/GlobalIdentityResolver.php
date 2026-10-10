<?php

declare(strict_types=1);

namespace Hyperf\Apex\RateLimit\Resolver;

use Hyperf\Apex\RateLimit\Contract\RateLimitIdentityResolverInterface;
use Hyperf\Apex\RateLimit\RateLimitScopes;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 接口维度全局共享：同一规则名下所有请求共用一个桶。
 *
 * 只保护「这个接口的总量」，不区分来源；要区分来源请用其它维度叠加规则。
 */
final class GlobalIdentityResolver implements RateLimitIdentityResolverInterface
{
    public function scope(): string
    {
        return RateLimitScopes::GLOBAL;
    }

    public function resolve(ServerRequestInterface $request): string
    {
        return 'all';
    }
}
