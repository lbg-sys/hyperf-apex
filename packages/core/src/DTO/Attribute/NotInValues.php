<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Contract\ValidationRuleAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use Hyperf\Validation\Rule;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class NotInValues implements ValidationRuleAttribute, SchemaAttribute
{
    /** @param list<null|bool|float|int|string> $values */
    public function __construct(public readonly array $values)
    {
    }

    public function validationRules(): array
    {
        return [Rule::notIn($this->values)];
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['not' => SchemaFragment::of(['enum' => $this->values])]);
    }
}
