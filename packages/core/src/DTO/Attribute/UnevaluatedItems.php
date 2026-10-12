<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\DocumentationOnlyAttribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use Hyperf\Apex\DTO\Schema\SchemaReference;

/**
 * 约束未被 prefixItems / items 覆盖到的剩余数组元素。
 *
 * 传 false 表示「不允许有多余元素」，传类型名表示多余元素必须是该类型。
 * 例：#[PrefixItems('number', 'number')] 配 #[UnevaluatedItems(false)] 得到严格两元元组。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class UnevaluatedItems implements SchemaAttribute, DocumentationOnlyAttribute
{
    public function __construct(public readonly bool|string $value = false)
    {
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of([
            'unevaluatedItems' => is_string($this->value) ? SchemaReference::to($this->value) : $this->value,
        ]);
    }
}
