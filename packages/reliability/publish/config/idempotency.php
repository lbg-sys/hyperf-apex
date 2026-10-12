<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    /* 已完成记录的保留时间；过期后相同请求 ID 可重新使用。 */
    'retention_seconds' => (int) env('IDEMPOTENCY_RETENTION_SECONDS', 604800),

    /* 处理中记录超过该时间视为执行进程失联，后续同键请求可接管。 */
    'processing_timeout_seconds' => (int) env('IDEMPOTENCY_PROCESSING_TIMEOUT_SECONDS', 300),

    /*
     * 响应体入库上限（字节），默认 256KB；设为 0 表示不限制。
     *
     * 记录要按 retention_seconds 保留（默认 7 天），不设上限时一次导出接口就会把整段响应
     * 写进库并持续占用空间（响应体列是 mediumText，16MB）。超限时不入库响应体：
     * 重复请求仍能拿到相同的状态码、也不会重复执行 —— 幂等的核心保证不受影响，只是拿不到响应体。
     */
    'max_response_bytes' => (int) env('IDEMPOTENCY_MAX_RESPONSE_BYTES', 262144),

    /* 每次调度清理的批次上限，避免大表清理长时间持锁。 */
    'cleanup_batch_size' => (int) env('IDEMPOTENCY_CLEANUP_BATCH_SIZE', 1000),
    'cleanup_max_batches' => (int) env('IDEMPOTENCY_CLEANUP_MAX_BATCHES', 1000),
];
