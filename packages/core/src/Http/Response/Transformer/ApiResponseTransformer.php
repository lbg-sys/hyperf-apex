<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\Response\Transformer;

use Hyperf\Apex\Enum\ErrorCode;
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

        $result = $this->response
            ->raw($encoder->encode($response))
            ->withHeader('content-type', $encoder->contentType());

        /*
         * 信封里的业务状态码决定 HTTP 状态码：控制器直接返回信封时
         * （`ApiResponseDTO::make(ErrorCode::RESOURCE_NOT_FOUND)`），此前一律是 200 ——
         * 响应体说「资源不存在」、HTTP 说「成功」，而文档（按 ErrorCode::getHttpStatus() 生成）、
         * 监控的错误率与客户端的重试策略全都按后者工作。
         *
         * 认不出的码（应用自定义错误码）不猜状态：保持响应对象原本的状态码。
         */
        $status = ErrorCode::tryFrom($response->code())?->getHttpStatus()->value;

        return $status === null ? $result : $result->withStatus($status);
    }
}
