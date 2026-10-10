<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use InvalidArgumentException;

/**
 * 数值必须是指定值的整数倍（JSON Schema multipleOf），例如金额按 0.01 步进、数量按 10 递增。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class MultipleOf implements SchemaAttribute
{
    public function __construct(public readonly float|int $value)
    {
    }

    public function fragment(): SchemaFragment
    {
        if ($this->value <= 0) {
            throw new InvalidArgumentException('multipleOf 必须是大于 0 的数值。');
        }

        return SchemaFragment::of(['multipleOf' => $this->value]);
    }
}
