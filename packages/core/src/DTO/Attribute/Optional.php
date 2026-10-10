<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\OptionalAttribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Optional implements OptionalAttribute
{
}
