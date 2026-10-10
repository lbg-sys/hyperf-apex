<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\Middleware;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Contract\ConfigInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class IntegrationTokenMiddleware extends AbstractMiddleware
{
    public const HEADER = 'X-Integration-Token';

    public function __construct(private readonly ConfigInterface $config)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $expectedToken = (string) $this->config->get('integration.integration_token', '');
        if ($expectedToken === '') {
            throw new RuntimeException('Integration token is not configured.');
        }

        $providedToken = $request->getHeaderLine(self::HEADER);
        if ($providedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            throw new BusinessException(ErrorCode::UNAUTHENTICATED);
        }

        return $handler->handle($request);
    }
}
