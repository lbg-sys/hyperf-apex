<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Contract;

interface ValidationRuleAttribute
{
    /** @return array<int, mixed> */
    public function validationRules(): array;
}
