<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    /* 已完成记录的保留时间；过期后相同请求 ID 可重新使用。 */
    'retention_seconds' => (int) env('IDEMPOTENCY_RETENTION_SECONDS', 604800),

    /* 处理中记录超过该时间视为执行进程失联，后续同键请求可接管。 */
    'processing_timeout_seconds' => (int) env('IDEMPOTENCY_PROCESSING_TIMEOUT_SECONDS', 300),

    /* 每次调度清理的批次上限，避免大表清理长时间持锁。 */
    'cleanup_batch_size' => (int) env('IDEMPOTENCY_CLEANUP_BATCH_SIZE', 1000),
    'cleanup_max_batches' => (int) env('IDEMPOTENCY_CLEANUP_MAX_BATCHES', 1000),
];
