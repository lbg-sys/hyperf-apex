<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\DocumentationOnlyAttribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

/**
 * 标记字段已废弃（JSON Schema deprecated），提示调用方不要再使用，但当前仍然兼容。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Deprecated implements SchemaAttribute, DocumentationOnlyAttribute
{
    public function __construct(public readonly bool $value = true)
    {
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['deprecated' => $this->value]);
    }
}
