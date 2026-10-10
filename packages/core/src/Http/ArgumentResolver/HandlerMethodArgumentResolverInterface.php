<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\ArgumentResolver;

use Psr\Http\Message\ServerRequestInterface;
use ReflectionParameter;

interface HandlerMethodArgumentResolverInterface
{
    public function supportsParameter(ReflectionParameter $parameter): bool;

    /** @param array<int|string, mixed> $arguments */
    public function resolveArgument(
        ReflectionParameter $parameter,
        ServerRequestInterface $request,
        array $arguments
    ): mixed;
}
