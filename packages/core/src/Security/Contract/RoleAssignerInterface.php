<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security\Contract;

/**
 * 给用户绑定内置角色。
 *
 * 供租户开通和系统用户初始化使用，业务模块只依赖该契约。
 */
interface RoleAssignerInterface
{
    /**
     * 绑定该用户类型对应的内置角色，重复调用是幂等的。
     */
    public function assignBuiltinRole(int $userId, ?int $tenantId, string $userType): void;
}
