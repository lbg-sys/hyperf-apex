<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

abstract class AbstractMiddleware implements MiddlewareInterface
{
    abstract public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface;
}
