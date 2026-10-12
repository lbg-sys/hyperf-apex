<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\DocumentationOnlyAttribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use Hyperf\Apex\DTO\Schema\SchemaReference;

/**
 * 约束未被 properties / patternProperties 等覆盖到的剩余对象属性。
 *
 * 与 additionalProperties 的区别：unevaluatedProperties 会把组合关键字
 * （allOf / oneOf 等）里已经声明过的属性也算作「已覆盖」，因此在继承结构上更准确。
 * 例：#[UnevaluatedProperties(false)] 禁止出现任何未声明的属性。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class UnevaluatedProperties implements SchemaAttribute, DocumentationOnlyAttribute
{
    public function __construct(public readonly bool|string $value = false)
    {
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of([
            'type' => 'object',
            'unevaluatedProperties' => is_string($this->value) ? SchemaReference::to($this->value) : $this->value,
        ]);
    }
}
