<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\EndpointVersion30Compatible\DTO;

use Hyperf\Apex\DTO\Attribute\FieldDescription;
use Hyperf\Apex\DTO\Attribute\Length;

/** 只用 OpenAPI 3.0 也表达得出来的声明：用来证明收口没有过严。 */
final readonly class SafeItemDTO
{
    public function __construct(
        #[FieldDescription('名称')]
        #[Length(max: 32)]
        public string $name = '',
    ) {
    }
}
