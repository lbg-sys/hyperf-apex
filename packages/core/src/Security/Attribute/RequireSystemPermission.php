<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security\Attribute;

use Attribute;
use BackedEnum;
use Hyperf\Apex\Contract\PermissionInterface;

/**
 * 声明系统管理端接口需要的权限声明（等同于 guard 为 'system' 的权限要求）。
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class RequireSystemPermission
{
    /** @var list<string> */
    public readonly array $permissions;

    public readonly string $guard;

    /**
     * @param array<array-key, BackedEnum|PermissionInterface|string>|BackedEnum|PermissionInterface|string $permission
     */
    public function __construct(
        array|BackedEnum|PermissionInterface|string $permission,
        public readonly string $mode = 'AND',
    ) {
        $this->guard = 'system';
        $all = is_array($permission) ? $permission : [$permission];
        $extracted = [];
        foreach ($all as $item) {
            $extracted[] = match (true) {
                $item instanceof PermissionInterface => $item->identifier(),
                $item instanceof BackedEnum => (string) $item->value,
                default => (string) $item,
            };
        }
        $this->permissions = array_values(array_unique($extracted));
    }
}
