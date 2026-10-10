<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

/**
 * 数值下界的开区间形式（JSON Schema exclusiveMinimum），取值必须严格大于该值。
 * 与闭区间的 #[Minimum]（允许等于）区分，例如价格必须大于 0 时用本 Attribute。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ExclusiveMinimum implements SchemaAttribute
{
    public function __construct(public readonly float|int $value)
    {
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['exclusiveMinimum' => $this->value]);
    }
}
