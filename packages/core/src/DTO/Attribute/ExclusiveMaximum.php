<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\DocumentationOnlyAttribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

/**
 * 数值上界的开区间形式（JSON Schema exclusiveMaximum），取值必须严格小于该值。
 * 与闭区间的 #[Maximum]（允许等于）区分，例如比例必须小于 1 时用本 Attribute。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ExclusiveMaximum implements SchemaAttribute, DocumentationOnlyAttribute
{
    public function __construct(public readonly float|int $value)
    {
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['exclusiveMaximum' => $this->value]);
    }
}
