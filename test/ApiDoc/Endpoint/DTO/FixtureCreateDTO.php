<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\Endpoint\DTO;

use Hyperf\Apex\DTO\Attribute\FieldDescription;
use Hyperf\Apex\DTO\Attribute\Length;

final readonly class FixtureCreateDTO
{
    public function __construct(
        #[FieldDescription('名称')]
        #[Length(min: 1, max: 32)]
        public string $name = '',
    ) {
    }
}
