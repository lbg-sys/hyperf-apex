<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Metadata;

use Hyperf\Apex\DTO\Attribute\MapOf;
use Hyperf\Apex\DTO\Contract\ArrayOfAttribute;
use Hyperf\Apex\DTO\Contract\ItemRuleAttribute;
use Hyperf\Apex\DTO\Contract\NonNullableAttribute;
use Hyperf\Apex\DTO\Contract\OptionalAttribute;
use Hyperf\Apex\DTO\Contract\RequiredAttribute;
use Hyperf\Apex\DTO\Contract\ValidationRuleAttribute;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;

final class DTOMetadataFactory
{
    /** @var array<class-string, DTOClassMetadata> */
    private static array $cache = [];

    /** @param class-string $class */
    public static function from(string $class): DTOClassMetadata
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }
        $reflection = new ReflectionClass($class);
        $parameters = $reflection->getConstructor()?->getParameters() ?? [];
        $parametersByName = [];
        foreach ($parameters as $parameter) {
            $parametersByName[$parameter->getName()] = $parameter;
        }
        $properties = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $parameter = $parametersByName[$property->getName()] ?? null;
            $type = $property->getType();
            $array = $property->getAttributes(ArrayOfAttribute::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
            $map = $property->getAttributes(MapOf::class)[0] ?? null;
            $properties[$property->getName()] = new DTOPropertyMetadata(
                $type,
                $property->getAttributes(RequiredAttribute::class, ReflectionAttribute::IS_INSTANCEOF) !== []
                    || ($parameter !== null && ! $parameter->isDefaultValueAvailable() && $property->getAttributes(OptionalAttribute::class, ReflectionAttribute::IS_INSTANCEOF) === []),
                $type !== null && $type->allowsNull() && $property->getAttributes(NonNullableAttribute::class, ReflectionAttribute::IS_INSTANCEOF) === [],
                self::itemExpression($array, $map),
                $property->getAttributes(ValidationRuleAttribute::class, ReflectionAttribute::IS_INSTANCEOF),
                $property->getAttributes(ItemRuleAttribute::class, ReflectionAttribute::IS_INSTANCEOF),
            );
        }
        return self::$cache[$class] = new DTOClassMetadata($parameters, $properties);
    }

    /**
     * 元素/值类型表达式。
     *
     * `#[MapOf(X)]` 也要在这里取值类型：map 的键是字符串、值是 X，`field.*` 通配同样成立，
     * 所以校验规则与 `#[ArrayOf(X)]` 完全一致。此前只识别 `#[ArrayOf]`，映射值一路不校验 ——
     * 文档写了值类型、服务端却照单全收（`#[MapOf('int')]` 传字符串也放行）。
     *
     * `mixed` 视为没有值类型约束，与 `#[MapOf]` 的默认值一致。
     *
     * @param null|ReflectionAttribute<ArrayOfAttribute> $array
     * @param null|ReflectionAttribute<MapOf> $map
     */
    private static function itemExpression(?ReflectionAttribute $array, ?ReflectionAttribute $map): ?TypeExpression
    {
        if ($array !== null) {
            return TypeExpression::parseOrLeaf($array->newInstance()->itemType());
        }

        if ($map === null) {
            return null;
        }

        $type = $map->newInstance()->type;

        return $type === 'mixed' ? null : TypeExpression::parseOrLeaf($type);
    }
}
