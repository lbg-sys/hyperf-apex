<?php

declare(strict_types=1);

use function Hyperf\Support\env;

/*
 * 集成调用配置。
 */
return [
    /*
     * 集成凭证：集成侧调用带 #[IntegrationAccess] 的接口时，必须把它放进请求头
     * `X-Integration-Token`（校验见 IntegrationTokenMiddleware）。
     *
     * 未配置时中间件**直接抛错**而不是放行 —— 「没配就没人管」是最危险的默认值。
     * 所以本地与测试环境也要显式给一个值（可以是任意非空占位串），而不是留空。
     */
    'integration_token' => (string) env('INTEGRATION_TOKEN', ''),
];
