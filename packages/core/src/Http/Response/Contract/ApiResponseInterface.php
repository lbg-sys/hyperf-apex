<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\Response\Contract;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Contract\Arrayable;

/** @extends Arrayable<string, mixed> */
interface ApiResponseInterface extends Arrayable
{
    /**
     * 业务状态码（`ErrorCode::SUCCESS` 为 0）。
     *
     * 出站时由它映射 HTTP 状态码（见 ApiResponseTransformer）：控制器直接返回信封时，
     * 响应体里的「资源不存在」不能在 HTTP 层表现成 200 —— 文档、监控错误率与客户端
     * 的重试策略都会跟着错。
     */
    public function code(): int;

    public static function success(mixed $data = null, string $message = '成功'): self;

    public static function make(ErrorCode|int $code, ?string $message = null, mixed $data = []): self;
}
