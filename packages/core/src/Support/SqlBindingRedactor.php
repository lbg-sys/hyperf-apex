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
        $position = 0;

        foreach (array_values($bindings) as $index => $value) {
            $position = strpos($sql, '?', $position);
            if ($position === false) {
                break;
            }

            $replacement = isset($sensitive[$index]) ? self::REDACTED : "'{$value}'";
            $sql = substr_replace($sql, $replacement, $position, 1);
            $position += strlen($replacement);
        }

        return $sql;
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

    /** 指定位置之前出现过多少个占位符，即该位置的占位符下标。 */
    private static function placeholderOrdinal(string $sql, int $offset): int
    {
        return substr_count(substr($sql, 0, $offset), '?');
    }
}
