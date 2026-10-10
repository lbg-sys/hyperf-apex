<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\Response\Transformer;

use Hyperf\Apex\Http\Response\ApiResponseFactory;
use Hyperf\Apex\Http\Response\Contract\ApiResponseInterface;
use Hyperf\Apex\Http\Response\Contract\ResponseTransformerInterface;
use Hyperf\Apex\Http\Response\ResponseEncoderResolver;
use Hyperf\Apex\Http\Response\ResponseTransformationContext;
use Hyperf\Contract\Arrayable;
use Hyperf\Contract\Jsonable;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponseInterface;
use Psr\Http\Message\ResponseInterface;

final class ApiResponseTransformer implements ResponseTransformerInterface
{
    public function __construct(
        private readonly ApiResponseFactory $responses,
        private readonly ResponseEncoderResolver $encoders,
        private readonly HttpResponseInterface $response,
    ) {
    }

    public function supports(ResponseTransformationContext $context): bool
    {
        return $context->value === null
            || is_array($context->value)
            || $context->value instanceof Arrayable
            || $context->value instanceof Jsonable;
    }

    public function transform(ResponseTransformationContext $context): ResponseInterface
    {
        $response = $context->value instanceof ApiResponseInterface
            ? $context->value
            : $this->responses->success($context->value);
        $encoder = $this->encoders->resolve($context->method);

        return $this->response
            ->raw($encoder->encode($response))
            ->withHeader('content-type', $encoder->contentType());
    }
}
