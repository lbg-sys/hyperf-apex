<?php

declare(strict_types=1);

namespace Hyperf\Apex\Enum\Traits;

use Hyperf\Apex\Security\Attribute\BelongsToGroup;
use Hyperf\Apex\Security\Contract\PermissionGroupInterface;
use ReflectionEnum;
use UnexpectedValueException;

/**
 * 权限枚举共享行为：解析 case 上的 BelongsToGroup 声明。
 *
 * @mixin \Hyperf\Apex\Security\Contract\PermissionCodeInterface
 */
trait PermissionCodeTrait
{
    use EnumTrait;

    public function identifier(): string
    {
        return (string) $this->value;
    }

    public function group(): PermissionGroupInterface
    {
        /** @var array<string, PermissionGroupInterface> $cache */
        static $cache = [];

        $key = static::class . '::' . $this->name;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $case = (new ReflectionEnum(static::class))->getCase($this->name);
        $attribute = $case->getAttributes(BelongsToGroup::class)[0] ?? null;
        if ($attribute === null) {
            throw new UnexpectedValueException(sprintf(
                'Permission group is not configured for %s::%s.',
                static::class,
                $this->name
            ));
        }

        return $cache[$key] = $attribute->newInstance()->group;
    }
}
