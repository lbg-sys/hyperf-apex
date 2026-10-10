<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Security\Contract\PrincipalContextInterface;
use Hyperf\Context\Context;

final class PrincipalContext implements PrincipalContextInterface
{
    private const CONTEXT_KEY = 'security.principal';

    public function set(Principal $principal): void
    {
        Context::set(self::CONTEXT_KEY, $principal);
    }

    public function get(): Principal
    {
        $principal = Context::get(self::CONTEXT_KEY);
        if (! $principal instanceof Principal) {
            throw new BusinessException(ErrorCode::UNAUTHENTICATED);
        }

        return $principal;
    }

    public function clear(): void
    {
        Context::destroy(self::CONTEXT_KEY);
    }
}
