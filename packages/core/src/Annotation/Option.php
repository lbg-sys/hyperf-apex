<?php

declare(strict_types=1);

namespace Hyperf\Apex\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

#[Attribute(Attribute::TARGET_CLASS)]
final class Option extends AbstractAnnotation
{
    public function __construct(public readonly string $code)
    {
    }
}
