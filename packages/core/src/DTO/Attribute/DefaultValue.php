<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

/**
 * 字段在文档中展示的默认值（JSON Schema default），说明调用方不传该字段时的取值。
 * 只影响文档表达，不参与实际赋值；默认值仍由 DTO 构造器参数决定。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class DefaultValue implements SchemaAttribute
{
    public function __construct(public readonly mixed $value)
    {
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['default' => $this->value]);
    }
}
