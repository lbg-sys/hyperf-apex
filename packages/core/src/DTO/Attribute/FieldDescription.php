<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class FieldDescription implements SchemaAttribute
{
    public function __construct(public readonly string $value)
    {
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['description' => $this->value]);
    }
}
