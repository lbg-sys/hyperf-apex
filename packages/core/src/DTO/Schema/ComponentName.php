<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Schema;

use ReflectionClass;

/**
 * OpenAPI 组件命名规则：取 DTO 的短类名。
 *
 * 组件名短类名的约定原本只活在 apidoc 的 Schema 工厂里，但 core 的 #[Discriminator]
 * 在生成 $ref 时也要按同一约定拼组件名，把它下沉到这里作为唯一实现，避免两处漂移。
 */
final class ComponentName
{
    public static function of(string $class): string
    {
        return (new ReflectionClass($class))->getShortName();
    }
}
