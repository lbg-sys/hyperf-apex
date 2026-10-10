<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security\Attribute;

use Attribute;
use BackedEnum;
use Hyperf\Apex\Contract\PermissionInterface;

/**
 * 声明接口需要的操作权限。
 *
 * 支持传入字符串、BackedEnum、PermissionInterface 或其数组，支持 guard 守卫标识及校验模式（AND / OR）。
 * 可重复声明，支持类级别与方法级别定义。
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class RequirePermission
{
    /** @var list<string> 提取后的权限字符串标识列表 */
    public readonly array $permissions;

    /**
     * @param array<array-key, BackedEnum|PermissionInterface|string>|BackedEnum|PermissionInterface|string $permission
     */
    public function __construct(
        array|BackedEnum|PermissionInterface|string $permission,
        public readonly ?string $guard = null,
        public readonly string $mode = 'AND',
    ) {
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
