<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\ApiDoc\Builder\SecurityResolver;
use Hyperf\Apex\Http\Middleware\IntegrationTokenMiddleware;
use Hyperf\Apex\Security\AccessDeclarationReader;
use Hyperf\Apex\Security\Attribute\ApiKeyAccess;
use Hyperf\Apex\Security\Attribute\Authenticated;
use Hyperf\Apex\Security\Attribute\IntegrationAccess;
use Hyperf\Apex\Security\Attribute\PublicAccess;
use Hyperf\Apex\Security\Attribute\RequirePermission;
use OpenApi\Spec as OA;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * security 由已有的访问声明推导（`AccessDeclarationReader` 的「方法级优先、回退类级」），
 * 因此文档与运行时鉴权共用同一份声明、不会漂移。
 *
 * 这里钉住的优先级是：集成 Token > API 密钥 > Bearer；公开接口返回 null（省略 security，
 * 因为编译器会过滤空数组，`security: []` 表达不出来）。
 */
final class SecurityResolverTest extends TestCase
{
    public function testPublicMethodHasNoSecurityAndRegistersNothing(): void
    {
        $resolver = $this->resolver();

        $this->assertNull($resolver->requirementsFor($this->method('public')));
        // 只注册被用到的方案：公开接口不该在 components 里留下无人引用的安全方案。
        $this->assertSame([], $resolver->components());
    }

    public function testAuthenticatedMethodRequiresBearer(): void
    {
        $resolver = $this->resolver();
        $requirements = $resolver->requirementsFor($this->method('authenticated'));

        $this->assertCount(1, $requirements);
        $this->assertSame('bearerAuth', $requirements[0]->scheme);

        $scheme = $resolver->components()[0];
        // 组件键走 `component`：swagger-php 6.12 起 `securityScheme` 作为组件键已弃用。
        $this->assertSame('bearerAuth', $scheme->component);
        $this->assertSame('http', $scheme->type);
        $this->assertSame('bearer', $scheme->scheme);
    }

    public function testPermissionCodeAloneImpliesBearer(): void
    {
        // 只声明权限码（没写 #[Authenticated]）也要认证：权限校验的前提是知道是谁。
        $requirements = $this->resolver()->requirementsFor($this->method('permissionOnly'));

        $this->assertNotNull($requirements);
        $this->assertSame('bearerAuth', $requirements[0]->scheme);
    }

    public function testIntegrationTokenWinsOverPermissionCode(): void
    {
        $resolver = $this->resolver();
        $requirements = $resolver->requirementsFor($this->method('integrationWithPermission'));

        $this->assertNotNull($requirements);
        $this->assertSame('integrationToken', $requirements[0]->scheme);

        // 凭证只有一种：调用方要看到的是「用哪把密钥」，而不是「先登录再拿 token」。
        $scheme = $resolver->components()[0];
        $this->assertSame('apiKey', $scheme->type);
        $this->assertSame(IntegrationTokenMiddleware::HEADER, $scheme->name);
        $this->assertSame('header', $scheme->in);
    }

    public function testApiKeyWinsOverBearer(): void
    {
        $resolver = $this->resolver();
        $requirements = $resolver->requirementsFor($this->method('apiKeyWithAuthenticated'));

        $this->assertNotNull($requirements);
        $this->assertSame('apiKeyAuth', $requirements[0]->scheme);
        $this->assertSame('X-Api-Key', $resolver->components()[0]->name);
    }

    public function testRequiredPermissionsAreExposedForTheDocument(): void
    {
        $this->assertSame(['demo.read'], $this->resolver()->requiredPermissions($this->method('permissionOnly')));
        $this->assertSame([], $this->resolver()->requiredPermissions($this->method('public')));
    }

    public function testComponentsContainOnlyUsedSchemesAndAreSorted(): void
    {
        $resolver = $this->resolver();
        $resolver->requirementsFor($this->method('authenticated'));
        $resolver->requirementsFor($this->method('integration'));

        $this->assertSame(
            ['bearerAuth', 'integrationToken'],
            array_map(static fn (OA\Security\Scheme $scheme): string => (string) $scheme->component, $resolver->components()),
        );
    }

    private function method(string $name): ReflectionMethod
    {
        return new ReflectionMethod(SecurityFixtureController::class, $name);
    }

    private function resolver(): SecurityResolver
    {
        return new SecurityResolver(new AccessDeclarationReader());
    }
}

/** 访问声明的各种组合，逐个对应一种 security 结论。 */
final class SecurityFixtureController
{
    #[PublicAccess]
    public function public(): void
    {
    }

    #[Authenticated]
    public function authenticated(): void
    {
    }

    #[IntegrationAccess]
    public function integration(): void
    {
    }

    #[Authenticated]
    #[ApiKeyAccess]
    public function apiKeyWithAuthenticated(): void
    {
    }

    #[RequirePermission('demo.read')]
    public function permissionOnly(): void
    {
    }

    #[IntegrationAccess]
    #[RequirePermission('demo.read')]
    public function integrationWithPermission(): void
    {
    }
}
