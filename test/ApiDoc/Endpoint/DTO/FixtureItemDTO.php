<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\Endpoint\DTO;

use Hyperf\Apex\DTO\Attribute\FieldDescription;
use Hyperf\Apex\DTO\Attribute\IntAsString;

final readonly class FixtureItemDTO
{
    public function __construct(
        #[FieldDescription('条目 ID')]
        #[IntAsString]
        public int $id = 0,
        #[FieldDescription('名称')]
        public string $name = '',
    ) {
    }
}
