<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Security;

use Hyperf\Apex\Enum\Traits\EnumTrait;
use Hyperf\Apex\Enum\Traits\PermissionCodeTrait;
use Hyperf\Apex\Security\AccessDeclarationReader;
use Hyperf\Apex\Security\Attribute\Authenticated;
use Hyperf\Apex\Security\Attribute\BelongsToGroup;
use Hyperf\Apex\Security\Attribute\PublicAccess;
use Hyperf\Apex\Security\Attribute\RequirePermission;
use Hyperf\Apex\Security\Attribute\RequireRole;
use Hyperf\Apex\Security\Contract\PermissionCodeInterface;
use Hyperf\Apex\Security\Contract\PermissionGroupInterface;
use Hyperf\Apex\Security\PermissionSet;
use Hyperf\Constants\Annotation\Constants;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

enum TestPermission: string
{
    case QUESTION_READ = 'question:read';
    case USER_CREATE = 'user:create';
}

#[Constants]
enum TestGroup: string implements PermissionGroupInterface
{
    use EnumTrait;

    case GENERAL = 'general';
}

#[Constants]
enum TestGroupedPermission: string implements PermissionCodeInterface
{
    use PermissionCodeTrait;

    #[BelongsToGroup(TestGroup::GENERAL)]
    case POST_READ = 'post:read';
}

#[Authenticated]
class SampleSecureController
{
    #[PublicAccess]
    public function publicAction(): void
    {
    }

    #[RequirePermission([TestPermission::QUESTION_READ, 'content:write'], guard: 'admin')]
    #[RequireRole('manager', guard: 'admin')]
    public function questionAction(): void
    {
    }

    public function defaultAction(): void
    {
    }
}

final class SecurityTest extends TestCase
{
    private AccessDeclarationReader $reader;

    protected function setUp(): void
    {
        $this->reader = new AccessDeclarationReader();
    }

    public function testMethodLevelPublicAccessOverridesClassAuthenticated(): void
    {
        $method = new ReflectionMethod(SampleSecureController::class, 'publicAction');
        $declaration = $this->reader->read($method);

        $this->assertTrue($declaration->public);
        $this->assertFalse($declaration->authenticated);
    }

    public function testMethodLevelRequirePermissionAndRole(): void
    {
        $method = new ReflectionMethod(SampleSecureController::class, 'questionAction');
        $declaration = $this->reader->read($method);

        $this->assertFalse($declaration->public);
        $this->assertSame(['question:read', 'content:write'], $declaration->permissions);
        $this->assertSame(['manager'], $declaration->roles);
        $this->assertSame('admin', $declaration->guard);
        $this->assertTrue($declaration->requiresPermission());
        $this->assertContains('question:read', $declaration->requiredCodeValues());
    }

    public function testFallbackToClassLevelAuthenticated(): void
    {
        $method = new ReflectionMethod(SampleSecureController::class, 'defaultAction');
        $declaration = $this->reader->read($method);

        $this->assertTrue($declaration->authenticated);
        $this->assertFalse($declaration->public);
    }

    public function testPermissionSet(): void
    {
        $set = new PermissionSet(['question:read', 'user:profile:read']);

        $this->assertTrue($set->has('question:read'));
        $this->assertTrue($set->has(TestPermission::QUESTION_READ));
        $this->assertFalse($set->has('non_existent'));

        $this->assertTrue($set->hasAll(['question:read', 'user:profile:read']));
        $this->assertFalse($set->hasAll(['question:read', 'missing']));

        $this->assertTrue($set->hasAny(['missing', 'user:profile:read']));
        $this->assertFalse($set->hasAny(['missing1', 'missing2']));
    }

    public function testPermissionCodeTraitAndGroup(): void
    {
        $permission = TestGroupedPermission::POST_READ;

        $this->assertSame('post:read', $permission->identifier());
        $this->assertSame(TestGroup::GENERAL, $permission->group());
    }
}
