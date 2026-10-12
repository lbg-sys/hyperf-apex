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
            /*
             * name 对 DTO 同样生效：`#[FromBody('payload')] StoreDTO $dto` 从 body 的 payload 子键水合，
             * 不写 name 时整个 body 就是 DTO 的来源。此前 DTO 分支把 name 静默忽略 ——
             * 声明写着取子键、实际拿的是整个 body，没有任何提示。
             */
            $named = $attribute->name === null ? null : ($body[$attribute->name] ?? null);

            return $this->hydrator->hydrate(
                $type->getName(),
                $attribute->name === null ? $body : (is_array($named) ? $named : []),
            );
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

        return $this->cast(
            $body[$name],
            $type instanceof ReflectionNamedType ? $type->getName() : 'mixed',
            $name,
            $parameter->allowsNull(),
        );
    }

    private function cast(mixed $value, string $type, string $name, bool $allowsNull): mixed
    {
        // 可空参数拿到空值算 null：`{"page": ""}` 与查询串的 `?page=` 是同一类形状。
        // 口径与 DTO 路径一致（见 RequestDataHydrator::castBuiltin 对可空类型的处理），
        // 否则同一个「空值」在两种参数形态下一个是 null、一个是 400。
        if ($allowsNull && is_string($value) && trim($value) === '') {
            return null;
        }

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
