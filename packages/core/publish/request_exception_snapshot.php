<?php

declare(strict_types=1);

use function Hyperf\Support\env;

$parsePatterns = static function (mixed $value): array {
    if (is_array($value)) {
        return array_values(array_map(
            static fn (string $item): string => trim($item),
            array_filter($value, static fn (mixed $item): bool => is_string($item) && trim($item) !== '')
        ));
    }
    if (! is_string($value) || trim($value) === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $value))));
};

/*
 * 黑名单：命中的路由不记快照。默认不排除任何路由 ——
 * 「哪些接口的请求体不适合落盘」是应用自己的判断，通用底座写死业务路由，
 * 别的项目拿到的就是一份含义错误的默认值。需要排除时用环境变量给出：
 * REQUEST_EXCEPTION_SNAPSHOT_BLACKLIST=/api/xxx/create,/api/xxx/*
 */
$blacklist = [
    ...$parsePatterns(env('REQUEST_EXCEPTION_SNAPSHOT_BLACKLIST', '')),
];

return [
    'enabled' => (bool) env('REQUEST_EXCEPTION_SNAPSHOT_ENABLED', false),
    'whitelist' => $parsePatterns(env('REQUEST_EXCEPTION_SNAPSHOT_WHITELIST', '')),
    'blacklist' => array_values(array_unique($blacklist)),
    'max_length' => (int) env('REQUEST_EXCEPTION_SNAPSHOT_MAX_LENGTH', 8192),
];
