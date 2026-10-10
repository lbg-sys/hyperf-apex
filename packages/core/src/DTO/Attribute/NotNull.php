<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\NonNullableAttribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class NotNull implements NonNullableAttribute
{
}
