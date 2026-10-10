<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\EndpointVersion30\DTO;

use Hyperf\Apex\DTO\Attribute\PatternProperties;

final readonly class Version30ItemDTO
{
    /** @param array<string, string> $labels */
    public function __construct(
        // patternProperties 在 OpenAPI 3.0 里没有对应表达：3.0 目标下必须报错而不是丢字段。
        #[PatternProperties(['^x-' => 'string'])]
        public array $labels = [],
    ) {
    }
}
