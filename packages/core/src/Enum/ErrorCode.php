<?php

declare(strict_types=1);

namespace Hyperf\Apex\Enum;

use Hyperf\Apex\Enum\Interface\EnumInterface;
use Hyperf\Apex\Enum\Traits\EnumTrait;
use Hyperf\Constants\Annotation\Constants;
use Hyperf\Constants\Annotation\Message;

#[Constants]
enum ErrorCode: int implements EnumInterface
{
    use EnumTrait;

    #[Message('成功')]
    case SUCCESS = 0;

    #[Message('业务处理失败')]
    case BUSINESS_ERROR = 10000;

    #[Message('请求参数错误')]
    case INVALID_ARGUMENT = 10001;

    #[Message('请求参数验证失败')]
    case VALIDATION_FAILED = 10002;

    #[Message('未登录或登录已失效')]
    case UNAUTHENTICATED = 10003;

    #[Message('无权进行此操作')]
    case PERMISSION_DENIED = 10004;

    #[Message('资源不存在')]
    case RESOURCE_NOT_FOUND = 10005;

    #[Message('资源状态冲突')]
    case RESOURCE_CONFLICT = 10006;

    #[Message('请求过于频繁')]
    case TOO_MANY_REQUESTS = 10007;

    #[Message('系统内部错误')]
    case INTERNAL_ERROR = 50000;

    #[Message('服务暂不可用')]
    case SERVICE_UNAVAILABLE = 50001;

    public function getHttpStatus(): HttpStatus
    {
        return match ($this) {
            self::SUCCESS => HttpStatus::OK,
            self::BUSINESS_ERROR, self::INVALID_ARGUMENT => HttpStatus::BAD_REQUEST,
            self::VALIDATION_FAILED => HttpStatus::UNPROCESSABLE_ENTITY,
            self::UNAUTHENTICATED => HttpStatus::UNAUTHORIZED,
            self::PERMISSION_DENIED => HttpStatus::FORBIDDEN,
            self::RESOURCE_NOT_FOUND => HttpStatus::NOT_FOUND,
            self::RESOURCE_CONFLICT => HttpStatus::CONFLICT,
            self::TOO_MANY_REQUESTS => HttpStatus::TOO_MANY_REQUESTS,
            self::INTERNAL_ERROR => HttpStatus::INTERNAL_SERVER_ERROR,
            self::SERVICE_UNAVAILABLE => HttpStatus::SERVICE_UNAVAILABLE,
        };
    }

    public static function fromHttpStatus(HttpStatus|int $status): self
    {
        $statusCode = $status instanceof HttpStatus ? $status->value : $status;

        return match ($statusCode) {
            200, 201, 202, 204, 206 => self::SUCCESS,
            400 => self::INVALID_ARGUMENT,
            401 => self::UNAUTHENTICATED,
            403 => self::PERMISSION_DENIED,
            404, 410 => self::RESOURCE_NOT_FOUND,
            409 => self::RESOURCE_CONFLICT,
            422 => self::VALIDATION_FAILED,
            429 => self::TOO_MANY_REQUESTS,
            503 => self::SERVICE_UNAVAILABLE,
            default => $statusCode >= 500 ? self::INTERNAL_ERROR : self::BUSINESS_ERROR,
        };
    }
}
