<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Security;

use Hyperf\Apex\Security\Principal;
use PHPUnit\Framework\TestCase;

final class PrincipalTest extends TestCase
{
    public function testClaimReturnsExistingValue(): void
    {
        $principal = $this->principal(claims: ['tenant_id' => 7]);

        $this->assertSame(7, $principal->claim('tenant_id', 0));
    }

    public function testClaimFallsBackToDefaultWhenKeyIsMissing(): void
    {
        $principal = $this->principal();

        $this->assertSame(0, $principal->claim('tenant_id', 0));
    }

    public function testClaimFallsBackToDefaultWhenValueIsNull(): void
    {
        // null 声明视作「未设置」：这与直接 `?? $default` 的读取语义一致，
        // 避免应用拿到 null 再各自判断一遍。
        $principal = $this->principal(claims: ['tenant_id' => null]);

        $this->assertSame(0, $principal->claim('tenant_id', 0));
    }

    public function testClaimReturnsNullWhenKeyIsMissingAndNoDefaultGiven(): void
    {
        $principal = $this->principal();

        $this->assertNull($principal->claim('missing'));
    }

    public function testClaimCarriesNonScalarValues(): void
    {
        $principal = $this->principal(claims: ['tags' => ['a', 'b'], 'name' => 'alice']);

        $this->assertSame(['a', 'b'], $principal->claim('tags', []));
        $this->assertSame('alice', $principal->claim('name', ''));
    }

    /** @param array<string, mixed> $claims */
    private function principal(array $claims = []): Principal
    {
        return new Principal(userId: 1, userType: 'user', username: 'alice', sessionId: 's-1', claims: $claims);
    }
}
