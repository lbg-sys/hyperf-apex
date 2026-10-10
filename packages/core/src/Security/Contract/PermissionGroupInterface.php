<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security\Contract;

use Hyperf\Apex\Enum\Interface\EnumInterface;

/**
 * 权限展示分组。
 *
 * 分组只用于权限树展示，不可授权、不入库。
 */
interface PermissionGroupInterface extends EnumInterface
{
}
