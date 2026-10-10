<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Idempotency\Support;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Security\Contract\PrincipalContextInterface;
use Hyperf\Apex\Security\Principal;

/**
 * 单测用的身份上下文替身。
 *
 * `get()` 在没设 principal 时抛 `BusinessException` —— 与真实上下文在集成端（无用户身份）
 * 的行为一致，正好用来测 `IdempotencyCallerResolver` 的「回退到集成 Token」分支。
 */
final class FakePrincipalContext implements PrincipalContextInterface
{
    private ?Principal $principal = null;

    public function set(Principal $principal): void
    {
        $this->principal = $principal;
    }

    public function get(): Principal
    {
        return $this->principal ?? throw new BusinessException(ErrorCode::UNAUTHENTICATED, '无身份上下文');
    }

    public function clear(): void
    {
        $this->principal = null;
    }
}
