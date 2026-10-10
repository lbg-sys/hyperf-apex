<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Contract\ValidationRuleAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Length implements ValidationRuleAttribute, SchemaAttribute
{
    public function __construct(public readonly ?int $min = null, public readonly ?int $max = null)
    {
    }

    public function validationRules(): array
    {
        return array_values(array_filter([$this->min === null ? null : 'min:' . $this->min, $this->max === null ? null : 'max:' . $this->max]));
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::ofPresent(['minLength' => $this->min, 'maxLength' => $this->max]);
    }
}
