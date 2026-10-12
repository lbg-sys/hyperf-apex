<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO;

use Hyperf\Apex\DTO\Metadata\DTOMetadataFactory;
use Hyperf\Apex\DTO\Metadata\DTOPropertyMetadata;
use Hyperf\Apex\DTO\Metadata\TypeExpression;
use LogicException;
use ReflectionNamedType;
use ReflectionType;

/**
 * 纯水合器：把关联数组按 DTO 的构造签名与属性类型，递归转成 DTO 实例。
 *
 * 只做类型转换、不做校验（校验是 {@see RequestDataHydrator} 的职责）。
 * 转型口径必须只有这一份：HTTP 请求水合与 Outbox 事件载荷水合都走这里，
 * 否则「文档说的类型」与「PHP 拿到的类型」会在两条路径上漂移。
 */
final class DTOHydrator
{
    /**
     * 已知具体类时的入口：调用方传类名字面量，返回值带具体类型。
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $data
     * @return T
     */
    public static function hydrate(string $class, array $data): object
    {
        return new $class(...self::arguments($class, $data));
    }

    /**
     * 类名来自运行时（反射 / 配置）时的入口：返回值只承诺是对象。
     *
     * @param array<string, mixed> $data
     */
    public static function instantiate(string $class, array $data): object
    {
        return new $class(...self::arguments($class, $data));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function arguments(string $class, array $data): array
    {
        $metadata = DTOMetadataFactory::from($class);
        $arguments = [];
        foreach ($metadata->parameters as $parameter) {
            $name = $parameter->getName();
            if (! array_key_exists($name, $data)) {
                if ($parameter->isDefaultValueAvailable()) {
                    continue;
                }

                /*
                 * 缺省值与可空都没有时，构造只能传 null，结果是 TypeError：
                 * 现象是「日志里只有一句 must be of type string, null given」。这类声明自相矛盾
                 * （最常见的是标了 #[Optional] 却没给默认值），必须在这里点名，
                 * 不能靠塞 null 蒙混过去 —— 那会把 DTO 的声明错误呈现成一条无从下手的 500。
                 */
                if (! $parameter->allowsNull()) {
                    throw new LogicException(sprintf(
                        '%s 的构造参数 $%s 没有默认值也不可空，数据里却没有该字段：请给它默认值、允许 null，或去掉 #[Optional]。',
                        $class,
                        $name,
                    ));
                }

                $arguments[$name] = null;
                continue;
            }

            $arguments[$name] = self::cast(
                $data[$name],
                $parameter->getType(),
                $metadata->properties[$name] ?? null,
            );
        }

        return $arguments;
    }

    private static function cast(mixed $value, ?ReflectionType $type, ?DTOPropertyMetadata $property): mixed
    {
        if ($value === null || ! $type instanceof ReflectionNamedType) {
            return $value;
        }

        if ($type->isBuiltin()) {
            if ($type->getName() !== 'array' || ! is_array($value) || $property === null) {
                return self::castBuiltin($value, $type->getName(), $type->allowsNull());
            }

            if ($property->itemType === null) {
                return $value;
            }

            return self::castList($value, $property->itemType);
        }

        return is_array($value) ? self::instantiate($type->getName(), $value) : $value;
    }

    /**
     * 数组元素按类型表达式的深度递归转型。
     *
     * array_map 保留字符串键，因此 array<string, X> 这类映射不会被重排成列表。
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function castList(array $value, TypeExpression $expression): array
    {
        if (! $expression->isLeaf()) {
            return array_map(
                fn (mixed $item): mixed => is_array($item) ? self::castList($item, $expression->item) : $item,
                $value,
            );
        }

        $itemType = $expression->name;

        return array_map(
            fn (mixed $item): mixed => class_exists($itemType) && is_array($item)
                ? self::instantiate($itemType, $item)
                : self::castBuiltin($item, $itemType, false),
            $value,
        );
    }

    private static function castBuiltin(mixed $value, string $type, bool $allowsNull = false): mixed
    {
        // 对于可空类型，空字符串应该转换为 null 而不是默认值
        if ($allowsNull && is_string($value) && trim($value) === '') {
            return null;
        }

        return match ($type) {
            'int' => is_numeric($value) ? (int) $value : ($allowsNull ? null : 0),
            'float' => is_numeric($value) ? (float) $value : ($allowsNull ? null : 0.0),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'string' => (string) $value,
            'array' => (array) $value,
            default => $value,
        };
    }
}
