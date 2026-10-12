<?php

declare(strict_types=1);

namespace Hyperf\Apex\RateLimit\Contract;

use Psr\Http\Message\ServerRequestInterface;

/**
 * 限流主体标识解析器。
 *
 * 这是通用限流器唯一的业务扩展点：核心只认识「维度（scope）+ 标识（identity）」
 * 这组抽象，不知道每个维度具体代表什么。业务模块实现本接口、放进
 * rate_limit.resolvers 配置即可引入自己的维度，不需要改动核心代码。
 */
interface RateLimitIdentityResolverInterface
{
    /**
     * 该解析器负责的维度名称，全局唯一。
     */
    public function scope(): string;

    /**
     * 解析当前请求在该维度下的主体标识。
     *
     * 无法确定时返回 null 或空串，由调用方跳过该条规则 —— 绝不能退化成一个共享桶，
     * 那等于让所有主体共用一个配额。
     */
    public function resolve(ServerRequestInterface $request): ?string;
}
