<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Contract;

/**
 * 作用在**数组元素**上的校验规则，由 DTORuleFactory 挂到 `字段.*`。
 *
 * 普通的 ValidationRuleAttribute 只能约束字段本身，表达不了「数组里每个元素都要满足」。
 * 需要区分这两层的 Attribute 实现本接口，而不是在 DTORuleFactory 里判断具体类名。
 */
interface ItemRuleAttribute
{
    /**
     * @return array<int, mixed> Hyperf 校验规则，可以是字符串或规则对象
     */
    public function itemValidationRules(): array;
}
