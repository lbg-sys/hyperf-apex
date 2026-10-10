<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security\Contract;

use Hyperf\Apex\Contract\PermissionInterface;
use Hyperf\Apex\Enum\Interface\EnumInterface;

/**
 * 可授权的叶子权限码枚举契约。
 *
 * 继承 PermissionInterface，标识该枚举可以作为权限码识别。
 * 所属分组由 case 上的 BelongsToGroup 声明。
 */
interface PermissionCodeInterface extends EnumInterface, PermissionInterface
{
    /**
     * 权限码所属的展示分组。
     */
    public function group(): PermissionGroupInterface;
}
