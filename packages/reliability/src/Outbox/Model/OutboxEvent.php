<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbox\Model;

use Hyperf\Apex\Database\Model\AbstractModel;

/**
 * @property int $id Outbox 事件 ID
 * @property string $scope_key 幂等隔离范围
 * @property string $event_type 稳定的业务事件类型
 * @property string $idempotency_key 业务幂等键
 * @property null|string $aggregate_type 关联业务资源类型
 * @property null|string $aggregate_id 关联业务资源 ID
 * @property array<string, mixed> $payload 事件载荷快照
 * @property string $payload_hash 规范化事件载荷摘要
 * @property string $status 执行状态
 * @property int $attempt_count 已认领执行次数
 * @property int $max_attempts 最大执行次数快照
 * @property null|string $next_attempt_time 下次允许执行时间
 * @property null|string $lock_token Worker 认领令牌
 * @property null|string $lock_expire_time Worker 认领过期时间
 * @property null|string $last_failure_type 最近失败类型
 * @property null|string $last_error 最后一次错误信息
 * @property null|string $result_summary 最后一次执行结果摘要
 * @property null|string $success_time 执行成功时间
 * @property null|string $fail_time 进入失败终态时间
 * @property int $replay_count 人工重放次数
 * @property null|string $last_replay_time 最近人工重放时间
 * @property string $create_time 创建时间
 * @property string $update_time 更新时间
 */
final class OutboxEvent extends AbstractModel
{
    protected ?string $table = 'outbox_events';

    /** @var list<string> */
    protected array $fillable = [
        'scope_key', 'event_type', 'idempotency_key', 'aggregate_type', 'aggregate_id',
        'payload', 'payload_hash', 'status', 'attempt_count', 'max_attempts', 'next_attempt_time',
        'lock_token', 'lock_expire_time', 'last_failure_type', 'last_error', 'result_summary',
        'success_time', 'fail_time', 'replay_count', 'last_replay_time',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'payload' => 'json',
        'attempt_count' => 'integer',
        'max_attempts' => 'integer',
        'replay_count' => 'integer',
    ];
}
