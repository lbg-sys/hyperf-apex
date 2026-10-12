<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\DocumentationOnlyAttribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Example implements SchemaAttribute, DocumentationOnlyAttribute
{
    public function __construct(public readonly mixed $value)
    {
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['example' => $this->value]);
    }
}
