<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\Response\Transformer;

use Hyperf\Apex\Http\Response\Contract\ResponseTransformerInterface;
use Hyperf\Apex\Http\Response\ResponseTransformationContext;
use Psr\Http\Message\ResponseInterface;

final class PassthroughResponseTransformer implements ResponseTransformerInterface
{
    public function supports(ResponseTransformationContext $context): bool
    {
        return $context->value instanceof ResponseInterface;
    }

    public function transform(ResponseTransformationContext $context): ResponseInterface
    {
        return $context->value;
    }
}
