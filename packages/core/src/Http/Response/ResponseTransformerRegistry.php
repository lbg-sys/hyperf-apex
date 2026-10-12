<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\Response;

use Hyperf\Apex\Http\Response\Contract\ResponseTransformerInterface;
use Hyperf\Apex\Http\Response\Transformer\ApiResponseTransformer;
use Hyperf\Apex\Http\Response\Transformer\PassthroughResponseTransformer;
use Hyperf\Contract\ConfigInterface;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionMethod;

final class ResponseTransformerRegistry
{
    /** @var list<ResponseTransformerInterface> */
    private array $transformers;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ConfigInterface $config,
    ) {
        $this->transformers = $this->createTransformers();
    }

    public function transform(
        mixed $value,
        ?ReflectionMethod $method,
        ServerRequestInterface $request,
    ): mixed {
        $context = new ResponseTransformationContext($value, $method, $request);
        foreach ($this->transformers as $transformer) {
            if ($transformer->supports($context)) {
                return $transformer->transform($context);
            }
        }

        return $value;
    }

    /** @return list<ResponseTransformerInterface> */
    private function createTransformers(): array
    {
        $transformers = array_merge(
            $this->config->get('response.response_transformers', []),
            [
                PassthroughResponseTransformer::class,
                ApiResponseTransformer::class,
            ]
        );
        $result = [];
        foreach ($transformers as $transformer) {
            if (is_string($transformer)) {
                $transformer = $this->container->get($transformer);
            }
            if (! $transformer instanceof ResponseTransformerInterface) {
                throw new InvalidArgumentException(sprintf(
                    '响应转换器必须实现 %s，当前是 %s。',
                    ResponseTransformerInterface::class,
                    is_object($transformer) ? $transformer::class : get_debug_type($transformer)
                ));
            }
            $result[] = $transformer;
        }
        return $result;
    }
}
