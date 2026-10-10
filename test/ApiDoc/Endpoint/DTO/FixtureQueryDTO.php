<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\Endpoint\DTO;

use Hyperf\Apex\DTO\Attribute\FieldDescription;
use Hyperf\Apex\DTO\Attribute\Length;
use Hyperf\Apex\DTO\Attribute\Minimum;

final readonly class FixtureQueryDTO
{
    public function __construct(
        #[FieldDescription('页码')]
        #[Minimum(1)]
        public int $page = 1,
        #[FieldDescription('关键词')]
        #[Length(max: 32)]
        public string $keyword = '',
    ) {
    }
}
