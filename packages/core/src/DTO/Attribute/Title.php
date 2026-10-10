<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

/**
 * 字段标题。与 #[FieldDescription] 的区别是 title 是短标签，description 是完整说明。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Title implements SchemaAttribute
{
    public function __construct(public readonly string $value)
    {
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['title' => $this->value]);
    }
}
