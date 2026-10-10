<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http;

use Closure;
use Hyperf\Di\ReflectionManager;
use Hyperf\HttpServer\Router\Dispatched;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionMethod;

final class HandlerMethodResolver
{
    public function resolve(ServerRequestInterface $request): ?ReflectionMethod
    {
        $dispatched = $request->getAttribute(Dispatched::class);
        if (! $dispatched instanceof Dispatched || $dispatched->handler === null) {
            return null;
        }

        $callback = $dispatched->handler->callback;
        if ($callback instanceof Closure || (! is_array($callback) && ! is_string($callback))) {
            return null;
        }

        if (is_string($callback)) {
            $handler = str_contains($callback, '@')
                ? explode('@', $callback, 2)
                : (str_contains($callback, '::') ? explode('::', $callback, 2) : [$callback, '__invoke']);
        } else {
            $handler = $callback;
        }

        [$controller, $action] = $handler;
        $controller = is_object($controller) ? $controller::class : $controller;
        return ReflectionManager::reflectMethod($controller, $action);
    }
}
