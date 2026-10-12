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
            throw new InvalidArgumentException(sprintf('响应编码器必须实现 %s，当前是 %s。', ResponseEncoderInterface::class, get_debug_type($encoder)));
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

            /*
             * 类级声明要沿继承链往上找：控制器常把动作放在抽象基类里，注解挂在具体子类上
             * （方法继承自父类，`getDeclaringClass()` 拿到的是父类）；子类覆写父类方法时
             * 又反过来丢父类的注解。只看声明类会让这两种写法静默失效 —— 声明了非 JSON 编码的
             * 接口返回 JSON，而文档按 `contentType()` 生成媒体类型，两边分叉。
             */
            for ($class = $method->getDeclaringClass(); $class !== false; $class = $class->getParentClass()) {
                $classAttribute = $class->getAttributes(ResponseEncoder::class)[0] ?? null;
                if ($classAttribute !== null) {
                    return $classAttribute->newInstance()->type;
                }
            }
        }

        $responseDto = new ReflectionClass($this->responses->responseDtoClass());
        $responseDtoAttribute = $responseDto->getAttributes(ResponseEncoder::class)[0] ?? null;
        return $responseDtoAttribute?->newInstance()->type ?? JsonResponseEncoder::class;
    }
}
