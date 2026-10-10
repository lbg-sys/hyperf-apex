<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\Response;

use Hyperf\Apex\Http\Response\Attribute\ResponseEncoder;
use Hyperf\Apex\Http\Response\Contract\ResponseEncoderInterface;
use Hyperf\Apex\Http\Response\Encoder\JsonResponseEncoder;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;

final class ResponseEncoderResolver
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ApiResponseFactory $responses,
    ) {
    }

    public function resolve(?ReflectionMethod $method = null): ResponseEncoderInterface
    {
        $class = $this->encoderClass($method);
        $encoder = $this->container->get($class);
        if (! $encoder instanceof ResponseEncoderInterface) {
            throw new InvalidArgumentException(sprintf('Response encoder must implement %s, %s given.', ResponseEncoderInterface::class, get_debug_type($encoder)));
        }
        return $encoder;
    }

    /** @return class-string<ResponseEncoderInterface> */
    private function encoderClass(?ReflectionMethod $method): string
    {
        if ($method !== null) {
            $methodAttribute = $method->getAttributes(ResponseEncoder::class)[0] ?? null;
            if ($methodAttribute !== null) {
                return $methodAttribute->newInstance()->type;
            }

            $classAttribute = $method->getDeclaringClass()->getAttributes(ResponseEncoder::class)[0] ?? null;
            if ($classAttribute !== null) {
                return $classAttribute->newInstance()->type;
            }
        }

        $responseDto = new ReflectionClass($this->responses->responseDtoClass());
        $responseDtoAttribute = $responseDto->getAttributes(ResponseEncoder::class)[0] ?? null;
        return $responseDtoAttribute?->newInstance()->type ?? JsonResponseEncoder::class;
    }
}
