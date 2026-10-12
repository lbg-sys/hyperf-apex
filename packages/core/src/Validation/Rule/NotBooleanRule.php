<?php

declare(strict_types=1);

namespace Hyperf\Apex\Validation\Rule;

use Hyperf\Validation\Contract\Rule;
use Hyperf\Validation\Contract\ValidatorAwareRule;
use Hyperf\Validation\Validator;

/**
 * 拒绝布尔值。
 *
 * 存在的理由：Hyperf 的 integer 规则底层是 `filter_var($value, FILTER_VALIDATE_INT)`，
 * 而 filter_var 会先把非字符串标量转成字符串 —— `true` 于是变成 "1"，`{"id": true}`
 * 被判为合法整数。水合层的 `is_numeric(true)` 却是 false，落到 `$allowsNull ? null : 0`
 * 的分支，最终结果是校验放行、值静默变成 0。
 *
 * 为什么不把 integer 整体换成本项目的规则对象（像 BooleanValueRule 换掉 boolean 那样）：
 * Hyperf 判断「该字段是不是数值规则」靠的是**规则名**（`Validator::$numericRules =
 * ['Numeric', 'Integer', 'Decimal']`），换成对象之后 min/max 会从数值比较退化成字符串长度
 * 比较 —— 数量 0 配上 min:1 会变成 mb_strlen('0') >= 1 直接通过，静默放行所有越界值。
 * 而且扩展机制也救不了：`Validator::validateAttribute()` 是直接调 `$this->validateInteger()`，
 * 该方法是真实存在的，`__call` 里的 `extend()` 永远轮不到。
 * 所以这里保留 'integer' 字符串，只追加这一条判据。
 *
 * float / number 字段不需要它：numeric 规则用的是 is_numeric()，本来就不认布尔。
 */
final class NotBooleanRule implements Rule, ValidatorAwareRule
{
    use ValidatorAwareTrait;

    public function passes(string $attribute, mixed $value): bool
    {
        return ! is_bool($value);
    }

    /**
     * 复用 validation.integer 的文案：这个判据唯一的使用场景就是整数字段，而 true 被拒
     * 的原因确实是「不是整数」，没必要为此在两个语言包里各加一条条目。
     *
     * @return list<string>|string
     */
    public function message(): array|string
    {
        $message = $this->validator->getTranslator()->get('validation.integer');

        return $message === 'validation.integer' ? [':attribute 必须是整数。'] : $message;
    }
}
