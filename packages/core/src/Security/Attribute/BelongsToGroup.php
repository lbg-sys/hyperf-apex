<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security\Attribute;

use Attribute;
use Hyperf\Apex\Security\Contract\PermissionGroupInterface;

/**
 * 声明权限码所属的展示分组，标注在权限枚举的 case 上。
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final class BelongsToGroup
{
    public function __construct(public readonly PermissionGroupInterface $group)
    {
    }
}
