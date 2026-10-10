<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\ArrayOfAttribute;

/**
 * 声明数组的元素类型。
 *
 * $type 是类型表达式：标量类型名、DTO 类名、枚举类名，或 list<X> / array<X> /
 * array<string, X> / X[] 这类嵌套写法（见 DTO\TypeExpression）。嵌套写法会同时作用于
 * 文档的 items、校验规则的 field.*.* 与水合时的逐层转型。
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class ArrayOf implements ArrayOfAttribute
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $property = null,
    ) {
    }

    public function itemType(): string
    {
        return $this->type;
    }

    public function targetProperty(): ?string
    {
        return $this->property;
    }
}
