<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\ArgumentResolver\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FromBody
{
    public function __construct(public ?string $name = null)
    {
    }
}
