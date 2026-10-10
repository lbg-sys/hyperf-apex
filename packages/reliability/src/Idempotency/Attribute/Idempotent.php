<?php

declare(strict_types=1);

namespace Hyperf\Apex\Idempotency\Attribute;

use Attribute;

/**
 * 声明接口需要幂等保护。
 *
 * 默认从 Idempotency-Key 请求头读取请求 ID；已有明确业务请求 ID 的接口可通过
 * $requestIdField 指定 JSON body 中的顶层字段，避免维护两套 ID。
 *
 * $caller 用于没有登录主体的固定来源（例如某个第三方回调）。登录接口会自动使用
 * 用户 ID，集成接口会使用集成 Token 的不可逆摘要，不应把密钥本身写入该参数。
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Idempotent
{
    public function __construct(
        public ?string $requestIdField = null,
        public ?string $caller = null,
    ) {
    }
}
