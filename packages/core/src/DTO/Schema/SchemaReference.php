<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Schema;

/**
 * 对另一个 Schema 的引用，用于在 Attribute 参数里表达嵌套 Schema。
 *
 * Attribute 无法访问 DTOSchemaFactory 的注册状态（也不应该访问 —— 它们在请求热路径上
 * 会被 newInstance()），因此组合类关键字（allOf / oneOf / contains / patternProperties 等）
 * 的嵌套 Schema 先以引用形式给出，由 DTOSchemaFactory 在装配时解析：
 * 项目 DTO 解析成 $ref 并注册组件，标量类型名解析成对应的基础类型片段。
 */
final readonly class SchemaReference
{
    private function __construct(public string $type)
    {
    }

    /**
     * @param string $type 类型表达式：DTO 类名、枚举类名、string/int/bool/float/array/mixed
     *                     等类型名，或 list<X> / array<string, X> / X[] 这类嵌套写法
     */
    public static function to(string $type): self
    {
        return new self($type);
    }

    /**
     * 批量转换，便于 Attribute 接受可变长类型参数。
     *
     * @param list<string> $types
     * @return list<self>
     */
    public static function all(array $types): array
    {
        return array_map(static fn (string $type): self => self::to($type), $types);
    }
}
