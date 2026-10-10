<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\ArgumentResolver;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Http\ArgumentResolver\Attribute\FromMultipart;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionNamedType;
use ReflectionParameter;

final class FromMultipartArgumentResolver implements HandlerMethodArgumentResolverInterface
{
    public function __construct(private readonly RequestDataHydrator $hydrator)
    {
    }

    public function supportsParameter(ReflectionParameter $parameter): bool
    {
        return $parameter->getAttributes(FromMultipart::class) !== [];
    }

    /** @param array<int|string, mixed> $arguments */
    public function resolveArgument(ReflectionParameter $parameter, ServerRequestInterface $request, array $arguments): mixed
    {
        $type = $parameter->getType();
        /*
         * 必须收窄到 ReflectionNamedType：基类 ReflectionType 没有 getName()，
         * 参数写成联合类型（`A|B`）时这一行会直接变成 500，而不是一句「参数无效」。
         * 内置类型同理 —— multipart 只承载 DTO，散装标量参数在这里没有水合路径。
         */
        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            // 参数声明写错是服务端代码缺陷，不是请求的问题：抛 LogicException 留痕，
            // 否则一个控制器签名错误会被包装成 400 让客户端去改请求，而服务端日志里什么都没有。
            throw new LogicException(sprintf(
                'Multipart 参数 $%s 必须声明成单一的 DTO 类型（不能是散装标量或联合类型）。',
                $parameter->getName(),
            ));
        }

        $body = $request->getParsedBody();
        if (! is_array($body)) {
            throw new BusinessException(ErrorCode::INVALID_ARGUMENT, 'Multipart 请求参数无效。');
        }

        return $this->hydrator->hydrate($type->getName(), $body);
    }
}
