<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\ItemRuleAttribute;
use Hyperf\Apex\DTO\Contract\ItemSchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class ItemMinimum implements ItemRuleAttribute, ItemSchemaAttribute
{
    public function __construct(public readonly float|int $value)
    {
    }

    public function itemValidationRules(): array
    {
        return ['min:' . $this->value];
    }

    public function itemSchemaFragment(): SchemaFragment
    {
        return SchemaFragment::of(['minimum' => $this->value]);
    }
}
