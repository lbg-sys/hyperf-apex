<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\ArgumentResolver;

use Hyperf\Apex\DTO\Metadata\DTOMetadataFactory;
use Hyperf\Apex\DTO\Metadata\DTOPropertyMetadata;
use Hyperf\Apex\DTO\Metadata\DTORuleFactory;
use Hyperf\Apex\DTO\Metadata\TypeExpression;
use Hyperf\Context\ResponseContext;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\Validation\ValidationException;
use LogicException;
use ReflectionNamedType;
use ReflectionType;

final class RequestDataHydrator
{
    public function __construct(private readonly ValidatorFactoryInterface $validatorFactory)
    {
    }

    /**
     * 校验并水合成 DTO 实例。
     *
     * 校验失败时带的是可空的响应对象：HTTP 请求期一定拿得到 ResponseContext，
     * 而 WebSocket 的消息协程里没有它，get() 会因返回类型不符直接 TypeError ——
     * 于是「参数没填对」会变成 500。这里用 getOrNull()，让非 HTTP 调用方自己去映射错误信封。
     *
     * @param array<string, mixed> $data
     */
    public function hydrate(string $class, array $data): object
    {
        $validator = $this->validatorFactory->make($data, DTORuleFactory::from($class));
        if ($validator->fails()) {
            throw new ValidationException($validator, ResponseContext::getOrNull());
        }

        return $this->create($class, $validator->validated());
    }

    /** @param array<string, mixed> $data */
    private function create(string $class, array $data): object
    {
        $metadata = DTOMetadataFactory::from($class);
        $arguments = [];
        foreach ($metadata->parameters as $parameter) {
            $name = $parameter->getName();
            if (! array_key_exists($name, $data)) {
                if ($parameter->isDefaultValueAvailable()) {
                    continue;
                }

                /*
                 * 缺省值与可空都没有时，构造只能传 null，结果是 TypeError 变成 500，
                 * 现象是「日志里只有一句 must be of type string, null given」。这类声明自相矛盾
                 * （最常见的是标了 #[Optional] 却没给默认值），必须在这里点名，
                 * 不能靠塞 null 蒙混过去 —— 那会把 DTO 的声明错误呈现成一条无从下手的 500。
                 */
                if (! $parameter->allowsNull()) {
                    throw new LogicException(sprintf(
                        '%s 的构造参数 $%s 没有默认值也不可空，请求里却没有该字段：请给它默认值、允许 null，或去掉 #[Optional]。',
                        $class,
                        $name,
                    ));
                }

                $arguments[$name] = null;
                continue;
            }

            $arguments[$name] = $this->cast(
                $data[$name],
                $parameter->getType(),
                $metadata->properties[$name] ?? null
            );
        }

        return new $class(...$arguments);
    }

    private function cast(mixed $value, ?ReflectionType $type, ?DTOPropertyMetadata $property): mixed
    {
        if ($value === null || ! $type instanceof ReflectionNamedType) {
            return $value;
        }

        if ($type->isBuiltin()) {
            if ($type->getName() !== 'array' || ! is_array($value) || $property === null) {
                return $this->castBuiltin($value, $type->getName(), $type->allowsNull());
            }

            if ($property->itemType === null) {
                return $value;
            }

            return $this->castList($value, $property->itemType);
        }

        return is_array($value) ? $this->create($type->getName(), $value) : $value;
    }

    /**
     * 数组元素按类型表达式的深度递归转型。
     *
     * array_map 保留字符串键，因此 array<string, X> 这类映射不会被重排成列表。
     * 类型表达式一律走 parseOrLeaf()：这里是 HTTP 请求热路径，不认识的写法
     * 退化成「按类型名原样处理」，与改动前的行为一致。
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private function castList(array $value, TypeExpression $expression): array
    {
        if (! $expression->isLeaf()) {
            return array_map(
                fn (mixed $item): mixed => is_array($item) ? $this->castList($item, $expression->item) : $item,
                $value
            );
        }

        $itemType = $expression->name;

        return array_map(fn (mixed $item): mixed => class_exists($itemType) && is_array($item)
            ? $this->create($itemType, $item)
            : $this->castBuiltin($item, $itemType, false), $value);
    }

    private function castBuiltin(mixed $value, string $type, bool $allowsNull = false): mixed
    {
        // 对于可空类型，空字符串应该转换为 null 而不是默认值
        if ($allowsNull && is_string($value) && trim($value) === '') {
            return null;
        }

        return match ($type) {
            'int' => is_numeric($value) ? (int) $value : ($allowsNull ? null : 0),
            'float' => is_numeric($value) ? (float) $value : ($allowsNull ? null : 0.0),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'string' => (string) $value,
            'array' => (array) $value,
            default => $value,
        };
    }
}
