<?php

declare(strict_types=1);

namespace Hyperf\Apex\RateLimit;

/**
 * 内置的限流维度标识。
 *
 * 这里刻意不使用枚举：维度是可扩展的，业务模块会通过
 * {@see Contract\RateLimitIdentityResolverInterface} 注册自己的维度，
 * 枚举会锁死可取值、逼迫新维度回来改核心代码。
 *
 * 维度只出现在 PHP 注解里，不进入接口契约，因此不适用面向接口字段的枚举规范。
 */
final class RateLimitScopes
{
    /** 按客户端 IP 限流，标识取自 ClientIpResolver（已处理可信代理与伪造）。 */
    public const IP = 'ip';

    /** 同一规则名下所有请求共享一个桶。 */
    public const GLOBAL = 'global';

    private function __construct()
    {
    }
}
