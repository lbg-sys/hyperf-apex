<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

/**
 * 要求数组元素互不重复（JSON Schema uniqueItems），例如 ID 列表、标签列表。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class UniqueItems implements SchemaAttribute
{
    public function __construct(public readonly bool $value = true)
    {
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['uniqueItems' => $this->value]);
    }
}
