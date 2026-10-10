<?php

declare(strict_types=1);

namespace Hyperf\Apex\Support;

/**
 * 字符串列表归一化。
 *
 * 配置项、落库 JSON 这类外部数据取出来可能是任意类型，统一按「非数组给空数组、
 * 非字符串元素丢弃」处理，调用方不必各自重写一遍 is_array + array_filter。
 */
final class StringList
{
    /** @return list<string> */
    public static function of(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item)));
    }
}
