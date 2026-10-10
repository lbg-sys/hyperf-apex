<?php

declare(strict_types=1);

namespace Hyperf\Apex\Support;

/**
 * LIKE 模式串构造。
 *
 * 用户输入里的 `%`、`_`、`\` 是 LIKE 的元字符，直接拼进模式串会让 `%` 退化成
 * `like '%%'`（匹配所有行，等于把关键字筛选悄悄取消）、`_` 退化成「任意单字符」。
 * 统一在这里转义，调用方只传原始关键字。
 *
 * MySQL / PostgreSQL 的 LIKE 默认转义符都是反斜杠，转义后不需要额外的 ESCAPE 子句。
 */
final class LikePattern
{
    /** 包含匹配的模式串：前后各补一个通配符。 */
    public static function contains(string $keyword): string
    {
        return '%' . self::escape($keyword) . '%';
    }

    /** 前缀匹配的模式串：转义后补一个尾通配符。 */
    public static function startsWith(string $prefix): string
    {
        return self::escape($prefix) . '%';
    }

    /**
     * 转义关键字里的 LIKE 元字符。
     *
     * 反斜杠必须在 `%`、`_` 之前替换，否则会把后两步新加的反斜杠再转义一遍。
     */
    public static function escape(string $keyword): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $keyword);
    }
}
