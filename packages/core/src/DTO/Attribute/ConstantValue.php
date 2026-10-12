<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\DocumentationOnlyAttribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

/**
 * 字段的唯一合法取值（JSON Schema const），表示该字段只能是这一个固定值。
 * 与 #[InValues] 的区别是这里只允许一个值，常用于固定的类型判别字段。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ConstantValue implements SchemaAttribute, DocumentationOnlyAttribute
{
    public function __construct(public readonly mixed $value)
    {
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['const' => $this->value]);
    }
}
