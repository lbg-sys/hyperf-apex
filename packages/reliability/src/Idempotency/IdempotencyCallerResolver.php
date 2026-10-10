<?php

declare(strict_types=1);

namespace Hyperf\Apex\Idempotency;

use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Http\Middleware\IntegrationTokenMiddleware;
use Hyperf\Apex\Idempotency\Attribute\Idempotent;
use Hyperf\Apex\Security\Contract\PrincipalContextInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class IdempotencyCallerResolver
{
    public function __construct(private readonly PrincipalContextInterface $principals)
    {
    }

    public function resolve(ServerRequestInterface $request, Idempotent $declaration): string
    {
        if ($declaration->caller !== null && trim($declaration->caller) !== '') {
            return hash('sha256', 'declared:' . trim($declaration->caller));
        }

        try {
            $principal = $this->principals->get();

            return hash('sha256', 'user:' . $principal->userId);
        } catch (BusinessException) {
            // 集成端没有 Principal，继续按已通过鉴权的集成 Token 区分调用方。
        }

        $integrationToken = $request->getHeaderLine(IntegrationTokenMiddleware::HEADER);
        if ($integrationToken !== '') {
            return hash('sha256', 'integration:' . $integrationToken);
        }

        throw new RuntimeException('幂等接口无法确定调用方：请将中间件放在鉴权之后，或在 #[Idempotent] 中声明 caller。');
    }
}
