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
 * access 日志（runtime/logs/access）的排除名单，按请求原始 path 做 fnmatch 匹配，
 * `*` 是通配符（例：/api/xxx/*）。
 *
 * 默认只排除与业务无关的基础设施路径。高频业务接口（心跳、上报这类）是 access 日志里
 * 量级最大的来源，但「哪些高频接口的留痕已经有别的去处」只有应用知道，
 * 通用底座写死业务路由，别的项目拿到的就是一份含义错误的默认值 ——
 * 需要排除时用环境变量给出：ACCESS_LOG_EXCLUDE_PATHS=/api/xxx/get_list,/api/xxx/*
 *
 * 排除健康探针：探针按秒级轮询，正常时没有任何信息量，只在故障时才值得看一眼 ——
 * 而排除只作用于正常完成的请求，未就绪的 503 照旧留痕（见 RequestTimingMiddleware::shouldLog）。
 *
 * 排除不覆盖故障：响应 5xx 或下游抛异常的请求照旧记 access 日志，
 * 否则「高频接口在报错」这件事会被静默掉，只剩一个跑得飞快的日志文件。
 */
$exclude = [
    '/health/*',
    // 指标抓取：Prometheus 按固定间隔拉取，正常时没有任何信息量。
    // 注意它只作用于 access 日志 —— 指标自身的埋点不跟随这份名单（高频接口正是最需要观测的）。
    '/metrics/*',
    ...$parsePatterns(env('ACCESS_LOG_EXCLUDE_PATHS', '')),
];

return [
    'exclude' => array_values(array_unique($exclude)),
];
