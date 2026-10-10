<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Metadata;

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
            $properties[$property->getName()] = new DTOPropertyMetadata(
                $type,
                $property->getAttributes(RequiredAttribute::class, ReflectionAttribute::IS_INSTANCEOF) !== []
                    || ($parameter !== null && ! $parameter->isDefaultValueAvailable() && $property->getAttributes(OptionalAttribute::class, ReflectionAttribute::IS_INSTANCEOF) === []),
                $type !== null && $type->allowsNull() && $property->getAttributes(NonNullableAttribute::class, ReflectionAttribute::IS_INSTANCEOF) === [],
                $array === null ? null : TypeExpression::parseOrLeaf($array->newInstance()->itemType()),
                $property->getAttributes(ValidationRuleAttribute::class, ReflectionAttribute::IS_INSTANCEOF),
                $property->getAttributes(ItemRuleAttribute::class, ReflectionAttribute::IS_INSTANCEOF),
            );
        }
        return self::$cache[$class] = new DTOClassMetadata($parameters, $properties);
    }
}
