<?php

declare(strict_types=1);

namespace Hyperf\Apex\Validation\Rule;

use Hyperf\Apex\DTO\Metadata\DTORuleFactory;
use Hyperf\Validation\Contract\Rule;

use function filter_var;

/**
 * 联合类型的合法性判定（`int|string`、`string|array` 这类）。
 *
 * 为什么需要它：联合类型没有单一类型名可以表达，此前整段不加类型规则 —— 请求
 * `{"value": {}}` 能通过校验，直到构造器赋值时才抛 TypeError，客户端拿到 500 而不是 422。
 * 而文档侧本来就用 oneOf 描述这些字段，这里补上同义的判定，三层结论才一致。
 *
 * 判定口径与 {@see DTORuleFactory} 的单一类型口径刻意保持一致
 * （`integer` 认数字字符串、布尔不算整数等），差别只在于「满足任一分支即可」。
 */
final class UnionTypeRule implements Rule
{
    /**
     * @param list<string> $types 分支类型名（`null` 已由 nullable 规则承担，不在这里重复）
     */
    public function __construct(private readonly array $types)
    {
    }

    public function passes(string $attribute, mixed $value): bool
    {
        foreach ($this->types as $type) {
            if (self::matches($type, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hyperf 的语言包按「单条规则名」组织，联合类型没有对应键，这里直接给出中文提示
     * （基类的其它规则回落到英文兜底文案，那条路只在语言键缺失时才会走到）。
     */
    public function message(): string
    {
        return sprintf(':attribute 的类型必须是 %s 之一。', implode(' / ', $this->types));
    }

    private static function matches(string $type, mixed $value): bool
    {
        return match ($type) {
            // 与 integer + NotBooleanRule 同一口径：filter_var 会把 true 变成 "1"，
            // 必须先排除布尔，否则 `{"value": true}` 会被当成合法整数放行。
            'int', 'integer' => ! is_bool($value)
                && (is_int($value) || (is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== false)),
            'float', 'number', 'numeric' => ! is_bool($value) && is_numeric($value),
            // 数字也算匹配字符串分支：`int|string` 里数字本来就能走 int 分支，
            // 放宽它不会让任何非法输入混进来，却避免把 123 这类合法输入挡下。
            'string' => is_string($value) || is_int($value) || is_float($value),
            'bool', 'boolean' => BooleanValueRule::accepts($value),
            'array', 'iterable' => is_array($value),
            // 剩下的可能是嵌套 DTO 的类名：JSON 里必须是对象形态（数组），水合后是实例。
            default => is_array($value) || $value instanceof $type,
        };
    }
}
