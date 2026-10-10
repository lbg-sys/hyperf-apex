<?php

declare(strict_types=1);

namespace Hyperf\Apex\Exception;

use Hyperf\Apex\Exception\Handler\AppExceptionHandler;
use Hyperf\Database\Model\ModelNotFoundException;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\Validation\ValidationException;
use Throwable;

/**
 * 「预期内的失败路径」判定。
 *
 * 口径的唯一来源是 {@see AppExceptionHandler}：这几类异常在那里被直接转成带错误码的业务响应，
 * 既不写 error 日志、也不触发未捕获异常告警。
 *
 * 运行指标必须用同一份判定，否则「未登录」「参数不合法」「扫描器打的 404」这些每天大量发生、
 * 且可观测性早已由 status_class=4xx 与 access 日志承担的事件会被算成异常 ——
 * 那个数字就永远不归零，真正的问题反而淹在里面。
 *
 * 改这里必须同步 AppExceptionHandler 的分支。两边分叉的表现是「日志里什么都没有，大屏却在报异常」。
 */
final class ExpectedException
{
    private function __construct()
    {
    }

    public static function matches(Throwable $throwable): bool
    {
        return $throwable instanceof BusinessException
            || $throwable instanceof ValidationException
            || $throwable instanceof ModelNotFoundException
            || $throwable instanceof HttpException;
    }
}
