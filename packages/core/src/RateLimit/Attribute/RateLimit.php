<?php

declare(strict_types=1);

namespace Hyperf\Apex\RateLimit\Attribute;

use Attribute;
use Hyperf\Apex\RateLimit\RateLimitScopes;

/**
 * 声明式限流。
 *
 * 可标注类或方法，方法级优先于类级；可重复标注以叠加多条规则（例如同时按 IP 与
 * 按业务主体限流）。维度依赖登录态等上下文时，限流中间件必须挂在提供该上下文的
 * 中间件之后，否则取不到标识只会静默跳过限流。
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class RateLimit
{
    public function __construct(
        /** 规则名，留空时由注解位置派生，保证不同接口互不干扰。 */
        public string $name = '',
        public string $scope = RateLimitScopes::IP,
        /** 桶容量，单位令牌。 */
        public int $capacity = 60,
        /** 每秒补充的令牌数。 */
        public int $refill = 1,
        /** 单次请求消耗的令牌数。 */
        public int $consume = 1,
        /** 覆盖默认拒绝文案。 */
        public ?string $message = null,
    ) {
    }
}
