<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\Middleware;

use Hyperf\Context\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestIdMiddleware extends AbstractMiddleware
{
    public const HEADER = 'X-Request-Id';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = trim($request->getHeaderLine(self::HEADER));
        if ($requestId === '') {
            $requestId = bin2hex(random_bytes(16));
            $request = $request->withHeader(self::HEADER, $requestId);
        }

        RequestContext::set($request);

        return $handler->handle($request)->withHeader(self::HEADER, $requestId);
    }
}
