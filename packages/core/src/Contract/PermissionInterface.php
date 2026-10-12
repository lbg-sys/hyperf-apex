<?php

declare(strict_types=1);

namespace Hyperf\Apex\Contract;

/**
 * 权限定义契约。
 *
 * 业务层枚举或权限值对象可实现此接口，以便与框架权限体系无缝集成。
 */
interface PermissionInterface
{
    /**
     * 获取权限唯一标识符（例如 "user:create"、"role:assign"）。
     */
    public function identifier(): string;
}
