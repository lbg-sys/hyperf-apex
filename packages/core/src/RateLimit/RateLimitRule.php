<?php

declare(strict_types=1);

namespace Hyperf\Apex\RateLimit;

use InvalidArgumentException;

/**
 * 一条限流规则。
 *
 * 规则只描述「以什么维度、多大的桶、每次消耗多少」，不含任何业务身份概念 ——
 * 主体标识由运行时的解析器按 scope 提供。
 */
final readonly class RateLimitRule
{
    public function __construct(
        /** 规则名，用于隔离不同接口的桶；为空时由注解位置派生。 */
        public string $name,
        /** 限流维度，对应某个 RateLimitIdentityResolverInterface 的 scope。 */
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
        /*
         * 三个数值必须为正，且在这里就把错误挑明。
         *
         * 以前是静默钳制成 1：`refill: 0`（想表达「不再补充」）会变成每秒补 1 个令牌，
         * 声明的规则与跑起来的规则不一致；而同一个 0 在 TTL 计算的 intdiv(..., $refill)
         * 里又会直接 DivisionByZeroError。同一个错误在一处崩溃、在另一处被静默改写，
         * 是最难查的组合 —— 一律在构造时拒绝。
         */
        foreach (['capacity' => $this->capacity, 'refill' => $this->refill, 'consume' => $this->consume] as $field => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException(sprintf(
                    '限流规则 %s 的 %s 必须是正整数，当前为 %d。',
                    $this->name,
                    $field,
                    $value,
                ));
            }
        }
    }
}
