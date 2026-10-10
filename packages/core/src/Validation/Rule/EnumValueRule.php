<?php

declare(strict_types=1);

namespace Hyperf\Apex\Validation\Rule;

use Hyperf\Apex\Enum\Interface\EnumInterface;
use Hyperf\Validation\Contract\Rule;
use Hyperf\Validation\Contract\ValidatorAwareRule;

/**
 * 枚举合法性校验规则。
 *
 * 为什么不用 Hyperf 自带的 Rule::enum：它在内部直接执行 `Enum::tryFrom($value)`，
 * 而其所在文件声明了 strict_types=1 —— int-backed 枚举收到 query / form 传来的
 * 数字字符串（如 "1"）会抛 TypeError 并被吞掉、判为失败。POST JSON 里的原生 int
 * 没问题，但 GET query 永远只可能是 string，int 枚举筛选条件全部中招。
 *
 * 本项目所有枚举都实现 EnumInterface 并提供 transform()，本身就带 int/string
 * backing 之间的归一能力（数字字符串 → int、整数 → string），因此这里直接复用它。
 */
final class EnumValueRule implements Rule, ValidatorAwareRule
{
    use ValidatorAwareTrait;

    /**
     * @param class-string<EnumInterface> $enum
     */
    public function __construct(private readonly string $enum)
    {
    }

    public function passes(string $attribute, mixed $value): bool
    {
        if ($value instanceof $this->enum) {
            return true;
        }

        // null / 对象 / 数组不属于请求标量；类型合法性（integer 等）由 DTO 类型规则负责
        if ($value === null || is_object($value) || is_array($value)) {
            return false;
        }

        // bool / float 等既不是枚举 backing 值，也不该被强转后蒙混过关
        if (! is_int($value) && ! is_string($value)) {
            return false;
        }

        return $this->enum::isValidValue($value);
    }

    /** @return list<string>|string */
    public function message(): array|string
    {
        $message = $this->validator->getTranslator()->get('validation.enum');

        return $message === 'validation.enum' ? ['The selected :attribute is invalid.'] : $message;
    }
}
