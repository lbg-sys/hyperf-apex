<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security\Attribute;

use Attribute;
use BackedEnum;
use InvalidArgumentException;

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
            $identifier = $item instanceof BackedEnum ? (string) $item->value : (string) $item;

            // 与 #[RequirePermission] 同一判据：空角色标识会让 isDeclared() 为真、
            // 却没有任何主体能持有它（表现为该接口对所有请求恒拒绝且看不出原因），
            // 声明错误必须在构造期报出来。
            if (trim($identifier) === '') {
                throw new InvalidArgumentException('角色标识不能为空：请给出字符串或 BackedEnum。');
            }

            $extracted[] = $identifier;
        }
        $this->roles = array_values(array_unique($extracted));
    }
}
