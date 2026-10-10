<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security\Contract;

use Hyperf\Apex\Security\PermissionSet;
use Hyperf\Apex\Security\Principal;

/**
 * 权限解析入口。
 *
 * 业务模块只依赖该契约，实现位于权限模块。
 */
interface PermissionCheckerInterface
{
    /**
     * 解析用户当前拥有的全部权限码。
     */
    public function resolve(Principal $principal): PermissionSet;

    /**
     * 递增用户的权限缓存版本，使已缓存的解析结果失效。
     *
     * @param list<int> $userIds
     */
    public function invalidate(array $userIds): void;
}
