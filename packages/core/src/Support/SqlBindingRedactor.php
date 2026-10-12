<?php

declare(strict_types=1);

namespace Hyperf\Apex\Support;

/**
 * SQL 日志的绑定值脱敏。
 *
 * SQL 日志把 bindings 拼回语句才可读，但 `password_hash`、`access_token`、`cookie`
 * 这类值一旦明文落进日志就等于泄漏。这里在拼接时先认出「值绑定给敏感列」的占位符，
 * 只把它们替换成 `[redacted]`，其余绑定照常展开 —— 整条 SQL 照旧可读，
 * 不然为了遮一个字段而丢掉全部参数，日志也就没用了。
 *
 * 敏感列判定一律走 {@see SensitiveDataFilter::isSensitiveKey()}，与异常快照、审计
 * 共用同一份口径，不在这里维护第二套关键字。
 *
 * 识别的上下文有限，认不出来就照常输出。宁可多展开一个值，也不因为解析失败把整条 SQL
 * 变成看不懂的东西（这类日志只在排查时被读，失败方向要偏「信息更全」）：
 * - 赋值与比较：`col = ?`、`col <> ?`、`col <= ?`、`col LIKE ?`；
 * - 列表：`col IN (?, ?)`；
 * - 写入：`INSERT INTO t (col, ...) VALUES (?, ...)`，按列顺序对齐值槽位。
 */
final class SqlBindingRedactor
{
    private const REDACTED = "'[redacted]'";

    /**
     * 把绑定值展开进 SQL，命中敏感列的绑定替换为 `[redacted]`。
     *
     * @param array<int, mixed> $bindings 位置绑定，顺序与 SQL 中的 `?` 一致
     */
    public static function interpolate(string $sql, array $bindings): string
    {
        if ($bindings === []) {
            return $sql;
        }

        $sensitive = self::sensitivePlaceholderIndexes($sql);
        $offsets = self::placeholderOffsets($sql);
        $values = array_values($bindings);

        // 从后往前替换：替换后面的不会影响前面已算好的位置。
        for ($index = count($values) - 1; $index >= 0; --$index) {
            if (! isset($offsets[$index])) {
                break;
            }

            $replacement = isset($sensitive[$index]) ? self::REDACTED : self::quote($values[$index]);
            $sql = substr_replace($sql, $replacement, $offsets[$index], 1);
        }

        return $sql;
    }

    /**
     * 真正的占位符在 SQL 中的位置（按出现顺序），跳过字符串字面量里的 `?`。
     *
     * SQL 里的 `?` 不都是占位符：`WHERE note LIKE '%?%'` 里那个就是字面量内容。按 `strpos`
     * 顺序展开会整体错位 —— 不仅后面的绑定全填错位置，敏感值判定也跟着错位，
     * 结果是本该遮住的密码以明文落进日志。
     *
     * 只跟踪引号状态与反斜杠转义（覆盖 MySQL / PostgreSQL 的常见写法）；
     * 认不出的语法按原文处理，与「失败方向偏信息更全」的取舍一致。
     *
     * @return list<int>
     */
    private static function placeholderOffsets(string $sql): array
    {
        $offsets = [];
        $quote = null;

        for ($index = 0, $length = strlen($sql); $index < $length; ++$index) {
            $character = $sql[$index];

            if ($quote !== null) {
                if ($character === '\\') {
                    ++$index;
                    continue;
                }
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
                continue;
            }

            if ($character === '?') {
                $offsets[] = $index;
            }
        }

        return $offsets;
    }

    /**
     * 绑定值展开成 SQL 字面量。
     *
     * 转义不只是可读性问题：`O'Brien` 不转义会输出成 `'O'Brien'`（日志失真），而含换行的值
     * 能在日志里伪造出完整的假行（log injection）—— 排查时读到的可能是假日志。
     */
    private static function quote(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . str_replace(
            ['\\', "'", "\n", "\r"],
            ['\\\\', "\\'", '\n', '\r'],
            (string) $value
        ) . "'";
    }

    /**
     * 找出需要脱敏的占位符下标（0 起，按 SQL 中出现顺序）。
     *
     * @return array<int, true>
     */
    private static function sensitivePlaceholderIndexes(string $sql): array
    {
        $indexes = [];

        // 赋值与比较：`password_hash = ?`、`access_token <> ?`、`remark LIKE ?`
        preg_match_all(
            '/(?<![<>=!])\b([a-zA-Z_][a-zA-Z0-9_.]*)\s*(?:=|<>|!=|<=|>=|<|>|like)\s*\?/i',
            $sql,
            $matches,
            PREG_OFFSET_CAPTURE,
        );
        foreach ($matches[0] as $i => [$match, $offset]) {
            if (SensitiveDataFilter::isSensitiveKey($matches[1][$i][0])) {
                $indexes[self::placeholderOrdinal($sql, $offset)] = true;
            }
        }

        // 列表：`lease_token IN (?, ?)`
        preg_match_all(
            '/\b([a-zA-Z_][a-zA-Z0-9_.]*)\s+in\s*\(([^)]*)\)/i',
            $sql,
            $matches,
            PREG_OFFSET_CAPTURE,
        );
        foreach ($matches[2] as $i => [$list, $offset]) {
            if (! SensitiveDataFilter::isSensitiveKey($matches[1][$i][0])) {
                continue;
            }

            $base = self::placeholderOrdinal($sql, $offset);
            for ($slot = 0, $count = substr_count($list, '?'); $slot < $count; ++$slot) {
                $indexes[$base + $slot] = true;
            }
        }

        // 写入：列顺序即值槽位顺序，槽位里的 `?` 才消耗一个绑定
        if (preg_match('/insert\s+into\s+\S+\s*\(([^)]*)\)\s*values\s*\(([^)]*)\)/i', $sql, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $columns = array_map('trim', explode(',', $matches[1][0]));
            $values = array_map('trim', explode(',', $matches[2][0]));
            $ordinal = self::placeholderOrdinal($sql, $matches[2][1]);

            foreach ($values as $slot => $token) {
                if ($token !== '?') {
                    continue;
                }
                if (isset($columns[$slot]) && SensitiveDataFilter::isSensitiveKey($columns[$slot])) {
                    $indexes[$ordinal] = true;
                }
                ++$ordinal;
            }
        }

        return $indexes;
    }

    /**
     * 指定位置之前有多少个真正的占位符，即该位置的占位符下标。
     *
     * 必须与 interpolate() 用同一套扫描：字面量里的 `?` 不算数，否则敏感索引会整体错位。
     */
    private static function placeholderOrdinal(string $sql, int $offset): int
    {
        $ordinal = 0;
        foreach (self::placeholderOffsets($sql) as $position) {
            if ($position >= $offset) {
                break;
            }
            ++$ordinal;
        }

        return $ordinal;
    }
}
