<?php

declare(strict_types=1);

namespace Hyperf\Apex\Exception\Contract;

use Throwable;

/**
 * 未捕获异常的上报口。
 *
 * 定义在 `Shared` 而不是让异常处理器直接依赖告警模块：异常处理器是所有请求的最后一道兜底，
 * 让 `Shared` 反向依赖某个业务模块，等于把这个兜底组件的可用性和那个模块绑死——
 * 告警模块的构造一旦出问题，整个 HTTP 异常处理都会跟着挂，本来只是「告警发不出去」
 * 会升级成「所有错误响应都拿不到」。
 *
 * 实现由容器绑定（见 `config/autoload/dependencies.php`），因此 `Shared` 不需要知道告警的存在。
 */
interface UnhandledExceptionReporterInterface
{
    /**
     * 上报一次未捕获异常。
     *
     * 实现必须自己吞掉所有异常：调用方是异常处理器，
     * 在那里再抛异常会把原始故障替换成上报故障，排查时看到的全是无关信息。
     *
     * @param null|string $route 命中的路由路径，取不到时为 null
     */
    public function report(Throwable $throwable, ?string $route): void;
}
