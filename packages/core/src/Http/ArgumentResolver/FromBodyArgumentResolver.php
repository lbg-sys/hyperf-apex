<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\ArgumentResolver;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Http\ArgumentResolver\Attribute\FromBody;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionNamedType;
use ReflectionParameter;

final class FromBodyArgumentResolver implements HandlerMethodArgumentResolverInterface
{
    public function __construct(private readonly RequestDataHydrator $hydrator)
    {
    }

    public function supportsParameter(ReflectionParameter $parameter): bool
    {
        return $parameter->getAttributes(FromBody::class) !== [];
    }

    /** @param array<int|string, mixed> $arguments */
    public function resolveArgument(ReflectionParameter $parameter, ServerRequestInterface $request, array $arguments): mixed
    {
        $attribute = $parameter->getAttributes(FromBody::class)[0]->newInstance();
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            return $this->hydrator->hydrate($type->getName(), $body);
        }

        $name = $attribute->name ?? $parameter->getName();
        if (! array_key_exists($name, $body)) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }
            if ($parameter->allowsNull()) {
                return null;
            }
            throw new BusinessException(ErrorCode::INVALID_ARGUMENT, sprintf('Body 参数 %s 必填。', $name));
        }

        return $this->cast($body[$name], $type instanceof ReflectionNamedType ? $type->getName() : 'mixed', $name);
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
            throw new BusinessException(ErrorCode::INVALID_ARGUMENT, sprintf('Body 参数 %s 类型不正确，应为 %s。', $name, $type));
        }
        return $result;
    }
}
