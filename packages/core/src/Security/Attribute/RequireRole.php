<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security\Attribute;

use Attribute;
use BackedEnum;

/**
 * 声明接口需要的角色身份。
 *
 * 支持传入字符串、BackedEnum 或其数组，支持 guard 守卫标识及校验模式（AND / OR）。
 * 可重复声明，支持类级别与方法级别定义。
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class RequireRole
{
    /** @var list<string> 提取后的角色标识列表 */
    public readonly array $roles;

    /**
     * @param array<array-key, BackedEnum|string>|BackedEnum|string $role
     */
    public function __construct(
        array|BackedEnum|string $role,
        public readonly ?string $guard = null,
        public readonly string $mode = 'AND',
    ) {
        $all = is_array($role) ? $role : [$role];
        $extracted = [];
        foreach ($all as $item) {
            $extracted[] = $item instanceof BackedEnum ? (string) $item->value : (string) $item;
        }
        $this->roles = array_values(array_unique($extracted));
    }
}
