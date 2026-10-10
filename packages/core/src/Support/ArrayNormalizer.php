<?php

declare(strict_types=1);

namespace Hyperf\Apex\Support;

/**
 * 数组结构归一化。
 *
 * 只做「按键排序」这一件事，调用方自己决定是否对顶层也排序：摘要场景若要兼容历史值，
 * 顶层键顺序必须保持不变，只能对子项排序。
 */
final class ArrayNormalizer
{
    /**
     * 递归按键排序：关联数组按字符串序重排键，list 保持原顺序。
     *
     * 排序是递归的，嵌套结构同样稳定；list 不排序，因为元素顺序本身就是语义。
     */
    public static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::sortKeys($item);
        }

        return $value;
    }
}
