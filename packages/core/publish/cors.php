<?php

declare(strict_types=1);

use function Hyperf\Support\env;

/*
 * 跨域白名单。
 *
 * 来源默认 `*`，即「任意站点都能读到本服务的响应」。默认值保持这个历史行为，是为了不让一次
 * 包升级把已有前端的跨域请求全掐断 —— 但生产环境应当收窄：填具体来源（协议 + 主机 + 端口，
 * 精确匹配，不做子域通配），或用环境变量 CORS_ALLOWED_ORIGINS 按逗号分隔给出。
 * 配成空字符串表示不下发任何跨域头（浏览器按同源策略拒绝）。
 */
$origins = array_values(array_filter(
    array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))),
    static fn (string $origin): bool => $origin !== ''
));

/*
 * 允许客户端携带的请求头。空值表示 `*`（放行任意请求头）：与 Allow-Origin 不同，
 * 这里放开不会泄漏信息，只是允许客户端多带几个头，因此不需要按来源收窄。
 *
 * 需要固定集合时列出来（逗号分隔）。不要改回「回显 Access-Control-Request-Headers」——
 * 那等于让请求方决定响应头的内容。
 */
$headers = array_values(array_filter(
    array_map('trim', explode(',', (string) env('CORS_ALLOWED_HEADERS', ''))),
    static fn (string $header): bool => $header !== ''
));

return [
    'allowed_origins' => $origins,
    'allowed_headers' => $headers,
];
