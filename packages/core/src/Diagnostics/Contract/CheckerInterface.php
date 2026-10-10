<?php

declare(strict_types=1);

namespace Hyperf\Apex\Diagnostics\Contract;

/**
 * 启动健康检查器契约。
 *
 * 宿主项目可实现此接口（如 DB 连通性、自定义存储驱动、特定三方 API 凭据等），并通过 startup_checks.checkers 注册。
 */
interface CheckerInterface
{
    /**
     * 执行检查。
     *
     * @return list<string> 检查失败原因列表（为空表示检查通过）
     */
    public function check(): array;
}
