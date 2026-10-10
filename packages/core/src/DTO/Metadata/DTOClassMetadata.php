<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Metadata;

use ReflectionParameter;

final readonly class DTOClassMetadata
{
    /**
     * @param list<ReflectionParameter> $parameters
     * @param array<string, DTOPropertyMetadata> $properties
     */
    public function __construct(public array $parameters, public array $properties)
    {
    }
}
