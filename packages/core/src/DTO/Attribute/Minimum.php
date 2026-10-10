<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Contract\ValidationRuleAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Minimum implements ValidationRuleAttribute, SchemaAttribute
{
    public function __construct(public readonly float|int $value)
    {
    }

    public function validationRules(): array
    {
        return ['min:' . $this->value];
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['minimum' => $this->value]);
    }
}
