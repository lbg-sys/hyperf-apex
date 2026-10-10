<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\ArgumentResolver;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Http\ArgumentResolver\Attribute\FromQuery;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionNamedType;
use ReflectionParameter;

final class FromQueryArgumentResolver implements HandlerMethodArgumentResolverInterface
{
    public function __construct(private readonly RequestDataHydrator $hydrator)
    {
    }

    public function supportsParameter(ReflectionParameter $parameter): bool
    {
        return $parameter->getAttributes(FromQuery::class) !== [];
    }

    /** @param array<int|string, mixed> $arguments */
    public function resolveArgument(ReflectionParameter $parameter, ServerRequestInterface $request, array $arguments): mixed
    {
        $attribute = $parameter->getAttributes(FromQuery::class)[0]->newInstance();
        $name = $attribute->name ?? $parameter->getName();
        $query = $request->getQueryParams();
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            return $this->hydrator->hydrate($type->getName(), $query);
        }

        if (! array_key_exists($name, $query)) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }
            if ($parameter->allowsNull()) {
                return null;
            }
            throw new BusinessException(ErrorCode::INVALID_ARGUMENT, sprintf('Query 参数 %s 必填。', $name));
        }

        return $this->cast($query[$name], $type instanceof ReflectionNamedType ? $type->getName() : 'mixed', $name);
    }

    private function cast(mixed $value, string $type, string $name): mixed
    {
        $result = match ($type) {
            'int' => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            'float' => filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'string' => is_scalar($value) ? (string) $value : null,
            'array' => is_array($value) ? $value : null,
            default => $value,
        };
        if ($result === null && $type !== 'mixed') {
            throw new BusinessException(ErrorCode::INVALID_ARGUMENT, sprintf('Query 参数 %s 类型不正确，应为 %s。', $name, $type));
        }
        return $result;
    }
}
