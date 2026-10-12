<?php

declare(strict_types=1);

namespace Hyperf\Apex\Support;

/**
 * 递归脱敏。
 *
 * 两个使用方共用同一份口径，不维护第二套规则：
 * - 异常快照中间件（RequestExceptionSnapshotMiddleware）；
 * - 审计的 change_data / extra_data —— 审计页面是长留存的，敏感值一旦落库就等于泄漏。
 *
 * 口径：
 * - 键名匹配用「包含」而非全等，`user_password` / `access_token` / `refreshToken` 都能命中；
 * - 递归深度上限 8 层，超出置 `[depth limited]`；
 * - 非标量、非数组的值置 `[类型名]`；
 * - 命中时值置 `[redacted]`。
 */
final class SensitiveDataFilter
{
    private const SENSITIVE_KEY_PARTS = [
        'password',
        'passwd',
        'token',
        'secret',
        'authorization',
        'cookie',
        'credential',
        // 密钥类字段名：`api_key` / `private_key` / `signature` 不会命中上面的词，
        // 但它们和密码一样 —— 一旦落进审计或异常快照就等于泄漏。
        // 裸 `key` 刻意不加：它作为普通字段名太常见（cache_key、sort_key），
        // 加进来会把大量正常字段遮掉，反而让人不敢信任脱敏结果。
        'api_key',
        'apikey',
        'private_key',
        'signature',
    ];

    private const MAX_DEPTH = 8;

    public static function filter(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return '[depth limited]';
        }

        if (! is_array($value)) {
            return is_scalar($value) || $value === null ? $value : '[' . get_debug_type($value) . ']';
        }

        $result = [];
        foreach ($value as $key => $item) {
            $keyString = (string) $key;
            $result[$keyString] = self::isSensitiveKey($keyString)
                ? '[redacted]'
                : self::filter($item, $depth + 1);
        }

        return $result;
    }

    public static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            if (str_contains($normalized, $part)) {
                return true;
            }
        }

        return false;
    }
}
