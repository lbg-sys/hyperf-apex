<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    'enabled' => (bool) env('OUTBOX_ENABLED', true),

    /*
     * 事件类型 → handler 的注册表：每个 handler 实现 OutboxHandlerInterface，
     * 由它自己的 eventType() 决定负责哪种事件，这里只列类名。
     * 同一个事件类型注册两个 handler 会在投递时直接报错，不会静默遮蔽其中一个。
     *
     * 示例：\App\Module\Order\Outbox\OrderPaidHandler::class,
     */
    'handlers' => [],

    'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 8),
    'event_max_attempts' => [],
    'lock_seconds' => (int) env('OUTBOX_LOCK_SECONDS', 300),
    'backoff_base_seconds' => (int) env('OUTBOX_BACKOFF_BASE_SECONDS', 30),
    'backoff_max_seconds' => (int) env('OUTBOX_BACKOFF_MAX_SECONDS', 1800),
    'backoff_jitter_ratio' => (float) env('OUTBOX_BACKOFF_JITTER_RATIO', 0.2),
    'error_max_length' => (int) env('OUTBOX_ERROR_MAX_LENGTH', 4000),

    /*
     * 消费进程。进程由注解自动注册，这里只控制是否孵化它 ——
     * 没有业务 Handler 时默认不启动：空转的常驻进程只会白白占资源。
     */
    'process' => [
        'enabled' => (bool) env('OUTBOX_PROCESS_ENABLED', false),
        'poll_interval_seconds' => (int) env('OUTBOX_PROCESS_POLL_INTERVAL_SECONDS', 2),
        'batch_size' => (int) env('OUTBOX_PROCESS_BATCH_SIZE', 50),
    ],

    /*
     * 仅清理成功记录；0 表示不清理，失败与死信永不自动删除。
     * 清理不会自动发生：把 outbox:prune 挂到 crontab（例如每天一次）。
     */
    'retention_days' => (int) env('OUTBOX_RETENTION_DAYS', 0),
];
