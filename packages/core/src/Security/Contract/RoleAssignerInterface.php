<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security\Contract;

/**
 * 给用户绑定内置角色。
 *
 * 只约定「按用户类型绑定内置角色」这一动作，角色的来源与绑定策略由实现方决定。
 */
interface RoleAssignerInterface
{
    /**
     * 绑定该用户类型对应的内置角色，重复调用是幂等的。
     */
    public function assignBuiltinRole(int $userId, string $userType): void;
}
