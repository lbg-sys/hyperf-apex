<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Idempotency;

use Hyperf\Apex\Http\Middleware\IntegrationTokenMiddleware;
use Hyperf\Apex\Idempotency\Attribute\Idempotent;
use Hyperf\Apex\Idempotency\IdempotencyCallerResolver;
use Hyperf\Apex\Security\Principal;
use Hyperf\Apex\Test\Idempotency\Support\FakePrincipalContext;
use Hyperf\HttpMessage\Server\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 调用方标识的解析顺序：声明优先 → 用户身份 → 集成 Token → 都取不到则报错。
 *
 * 标识直接决定限流/幂等的桶归属，顺序错了就是「两个不同调用方共用一个桶」或反之。
 */
final class IdempotencyCallerResolverTest extends TestCase
{
    public function testDeclaredCallerWins(): void
    {
        $context = new FakePrincipalContext();
        $context->set(new Principal(userId: 1, tenantId: null, agentId: null, userType: 'user', username: 'alice', sessionId: 's-1'));

        $resolver = new IdempotencyCallerResolver($context);

        $this->assertSame(
            hash('sha256', 'declared:partner-a'),
            $resolver->resolve($this->request(), new Idempotent(caller: 'partner-a')),
        );
    }

    public function testPrincipalUserIsUsed(): void
    {
        $context = new FakePrincipalContext();
        $context->set(new Principal(userId: 42, tenantId: null, agentId: null, userType: 'user', username: 'alice', sessionId: 's-1'));

        $resolver = new IdempotencyCallerResolver($context);

        $this->assertSame(hash('sha256', 'user:42'), $resolver->resolve($this->request(), new Idempotent()));
    }

    public function testIntegrationTokenIsUsedWhenThereIsNoPrincipal(): void
    {
        // 没设 principal = get() 抛业务异常 = 集成端场景。
        $resolver = new IdempotencyCallerResolver(new FakePrincipalContext());

        $request = (new Request('POST', '/fixture'))
            ->withHeader(IntegrationTokenMiddleware::HEADER, 'token-1');

        $this->assertSame(hash('sha256', 'integration:token-1'), $resolver->resolve($request, new Idempotent()));
    }

    public function testCannotDetermineCallerThrows(): void
    {
        // 既没有身份、也没有集成 Token：说明中间件挂在了鉴权之前，属配置错误，报错而不是瞎猜。
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('无法确定调用方');

        (new IdempotencyCallerResolver(new FakePrincipalContext()))->resolve($this->request(), new Idempotent());
    }

    private function request(): Request
    {
        return new Request('POST', '/fixture');
    }
}
