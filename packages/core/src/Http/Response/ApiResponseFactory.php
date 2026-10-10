<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\Response;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Http\Response\Contract\ApiResponseInterface;
use Hyperf\Contract\ConfigInterface;
use InvalidArgumentException;

final class ApiResponseFactory
{
    /** @var class-string<ApiResponseInterface> */
    private readonly string $responseDtoClass;

    public function __construct(ConfigInterface $config)
    {
        $class = $config->get('response.response_dto');
        if (! is_string($class) || ! is_subclass_of($class, ApiResponseInterface::class)) {
            throw new InvalidArgumentException(sprintf(
                'Response DTO must implement %s, %s given.',
                ApiResponseInterface::class,
                is_string($class) ? $class : get_debug_type($class)
            ));
        }
        $this->responseDtoClass = $class;
    }

    /** @return class-string<ApiResponseInterface> */
    public function responseDtoClass(): string
    {
        return $this->responseDtoClass;
    }

    public function success(mixed $data = [], string $message = '成功'): ApiResponseInterface
    {
        return $this->responseDtoClass::success($data, $message);
    }

    public function make(ErrorCode|int $code, ?string $message = null, mixed $data = []): ApiResponseInterface
    {
        return $this->responseDtoClass::make($code, $message, $data);
    }
}
