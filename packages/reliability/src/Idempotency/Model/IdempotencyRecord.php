<?php

declare(strict_types=1);

namespace Hyperf\Apex\Idempotency\Model;

use Hyperf\Apex\Database\Model\AbstractModel;

/**
 * @property int $id 幂等记录 ID
 * @property string $caller_hash 调用方标识摘要
 * @property string $endpoint 接口标识
 * @property string $request_id 业务请求 ID
 * @property string $request_hash 请求载荷摘要
 * @property string $status 执行状态
 * @property null|string $lock_token 本次认领令牌，接管时被覆盖
 * @property null|int $response_status HTTP 响应状态码
 * @property null|string $response_content_type HTTP 响应内容类型
 * @property null|string $response_body HTTP 响应体
 * @property string $expire_time 记录过期时间
 * @property string $create_time 创建时间
 * @property string $update_time 更新时间
 */
final class IdempotencyRecord extends AbstractModel
{
    protected ?string $table = 'idempotency_records';

    /** @var list<string> */
    protected array $fillable = [
        'caller_hash', 'endpoint', 'request_id', 'request_hash', 'status', 'lock_token',
        'response_status', 'response_content_type', 'response_body', 'expire_time',
    ];

    /** @var array<string, string> */
    protected array $casts = [
        'response_status' => 'integer',
    ];
}
