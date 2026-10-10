<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\Endpoint\DTO;

use Hyperf\Apex\DTO\Attribute\FieldDescription;
use Hyperf\Apex\DTO\Attribute\Length;

final readonly class FixtureUploadDTO
{
    public function __construct(
        #[FieldDescription('备注')]
        #[Length(max: 64)]
        public string $remark = '',
    ) {
    }
}
