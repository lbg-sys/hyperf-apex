<?php

declare(strict_types=1);

use Hyperf\Apex\RateLimit\Resolver\ClientIpIdentityResolver;
use Hyperf\Apex\RateLimit\Resolver\GlobalIdentityResolver;

use function Hyperf\Support\env;

return [
    // 总开关，故障时可在不发版的情况下关闭。
    'enabled' => (bool) env('RATE_LIMIT_ENABLED', true),

    // Redis 连接池。1000 QPS 下不需要独立池，这里保留开关只为隔离性
    // （避免限流与业务缓存互相挤占），默认复用 default。
    'redis_pool' => (string) env('RATE_LIMIT_REDIS_POOL', 'default'),

    'prefix' => (string) env('RATE_LIMIT_PREFIX', 'core:rl:'),

    // 主体标识解析器，业务维度在此注册。
    'resolvers' => [
        ClientIpIdentityResolver::class,
        GlobalIdentityResolver::class,
        /*
         * 业务端按需扩展（如按 API 密钥限流）：实现 RateLimitIdentityResolverInterface 并在此注册。
         * 示例：\App\Module\Auth\RateLimit\ApiKeyIdentityResolver::class,
         */
    ],

    // Redis 不可用时的行为：open=放行，close=拒绝。
    'failure_mode' => (string) env('RATE_LIMIT_FAILURE_MODE', 'open'),

    // TTL 随机抖动比例，避免同类 key 集中过期造成 Redis CPU 尖峰。
    'ttl_jitter_ratio' => 0.1,

    // 豁免的客户端 IP（内网、监控探针），支持 CIDR。
    'whitelist_ips' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('RATE_LIMIT_WHITELIST_IPS', '')))
    )),
];
