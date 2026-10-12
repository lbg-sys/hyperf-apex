<?php

declare(strict_types=1);

namespace Hyperf\Apex\Validation\Rule;

use Hyperf\Validation\Contract\Rule;
use Hyperf\Validation\Contract\ValidatorAwareRule;

use function filter_var;

/**
 * 布尔合法性校验规则。
 *
 * 为什么不用 Hyperf 自带的 boolean：它用严格比较 in_array($value, [true, false, 0, 1, '0', '1'], true)，
 * 只认原生布尔和数字。GET query、form 里的布尔只可能是字符串，"true" / "false" 一律判失败；
 * 而 POST JSON 里是真布尔所以看不出来。文档写 boolean、水合层用 FILTER_VALIDATE_BOOLEAN 又能
 * 认出 "true"，只有校验这一层比两边都严，前端按文档传 true 就被挡下。
 *
 * 判定口径刻意与 RequestDataHydrator::castBuiltin 的 bool 分支保持同一份 filter_var 语义
 * （true/false/1/0/on/off/yes/no，大小写不敏感），不再出现「校验拒绝但水合能转」的错位；
 * 唯一的例外是空字符串 —— 那是「没传值」而不是布尔，交给 nullable 规则处理。
 */
final class BooleanValueRule implements Rule, ValidatorAwareRule
{
    use ValidatorAwareTrait;

    public function passes(string $attribute, mixed $value): bool
    {
        return self::accepts($value);
    }

    /**
     * 判定口径单独暴露：联合类型的判定（{@see UnionTypeRule}）遇到 bool 分支时复用这一份实现。
     *
     * 同一份布尔口径写两遍时，两处漂移不会报错 —— 表现只是「int|string」与「bool」两个字段
     * 对同一个输入给出不同结论。
     */
    public static function accepts(mixed $value): bool
    {
        if (is_bool($value)) {
            return true;
        }

        if (is_int($value)) {
            return $value === 0 || $value === 1;
        }

        if (is_string($value) && $value !== '') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null;
        }

        return false;
    }

    /** @return list<string>|string */
    public function message(): array|string
    {
        $message = $this->validator->getTranslator()->get('validation.boolean');

        return $message === 'validation.boolean' ? [':attribute 必须是布尔值。'] : $message;
    }
}
