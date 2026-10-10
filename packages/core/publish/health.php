<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    /*
     * 就绪探针（/health/ready）探测的 Redis 连接池。
     *
     * 默认与业务共用 default 池，保证「探针说 Redis 正常」与「业务真的能用 Redis」
     * 是同一件事。只有需要给探针单独配额（例如探针频率高、不想和业务抢连接）时才改成独立池，
     * 独立池仍需在 config/autoload/redis.php 里声明。
     */
    'redis_pool' => (string) env('HEALTH_REDIS_POOL', 'default'),
];
