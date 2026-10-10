<?php

declare(strict_types=1);

namespace Hyperf\Apex\Exception;

use Hyperf\Apex\Enum\HttpStatus;
use Hyperf\Database\Model\ModelNotFoundException;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\Validation\ValidationException;
use Throwable;

/**
 * 异常最终会产生的 HTTP 状态码，与 AppExceptionHandler 的输出一一对应。
 *
 * 为什么需要它：异常处理器在中间件**外层**，中间件拿不到它生成的响应，只能拿异常本身反推状态码。
 * 没有这份映射时只能按 500 兜底，于是「未登录」这类业务拒绝全被记成 5xx，
 * 大屏的错误率被业务拒绝顶高 —— 而它本该只反映服务端故障。
 *
 * 异常处理器也用它取值，不各写一份：两处一旦分叉，就会出现「响应是 401、指标记 5xx」这种
 * 只在指标上看得到的偏差。
 */
final class ExceptionStatus
{
    private function __construct()
    {
    }

    public static function resolve(Throwable $throwable): int
    {
        if ($throwable instanceof BusinessException) {
            return self::normalize($throwable->getHttpStatus()->value);
        }

        if ($throwable instanceof ValidationException) {
            return HttpStatus::UNPROCESSABLE_ENTITY->value;
        }

        if ($throwable instanceof ModelNotFoundException) {
            return HttpStatus::NOT_FOUND->value;
        }

        if ($throwable instanceof HttpException) {
            return self::normalize($throwable->getStatusCode());
        }

        return HttpStatus::INTERNAL_SERVER_ERROR->value;
    }

    /**
     * 越界的状态码回落 500：调用方不该从响应里看到一个不存在的状态码，
     * 与异常处理器处理 HttpException 的边界判断保持一致。
     */
    private static function normalize(int $status): int
    {
        return $status >= 100 && $status <= 599 ? $status : HttpStatus::INTERNAL_SERVER_ERROR->value;
    }
}
