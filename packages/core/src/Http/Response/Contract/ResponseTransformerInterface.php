<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\Response\Contract;

use Hyperf\Apex\Http\Response\ResponseTransformationContext;

interface ResponseTransformerInterface
{
    public function supports(ResponseTransformationContext $context): bool;

    public function transform(ResponseTransformationContext $context): mixed;
}
