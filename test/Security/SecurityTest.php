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
    case RECORD_READ = 'record:read';
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
    case ITEM_READ = 'item:read';
}

#[Authenticated]
class SampleSecureController
{
    #[PublicAccess]
    public function publicAction(): void
    {
    }

    #[RequirePermission([TestPermission::RECORD_READ, 'record:write'], guard: 'admin')]
    #[RequireRole('manager', guard: 'admin')]
    public function recordAction(): void
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
        $method = new ReflectionMethod(SampleSecureController::class, 'recordAction');
        $declaration = $this->reader->read($method);

        $this->assertFalse($declaration->public);
        $this->assertSame(['record:read', 'record:write'], $declaration->permissions);
        $this->assertSame(['manager'], $declaration->roles);
        $this->assertSame('admin', $declaration->guard);
        $this->assertTrue($declaration->requiresPermission());
        $this->assertContains('record:read', $declaration->requiredCodeValues());
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
        $set = new PermissionSet(['record:read', 'user:profile:read']);

        $this->assertTrue($set->has('record:read'));
        $this->assertTrue($set->has(TestPermission::RECORD_READ));
        $this->assertFalse($set->has('non_existent'));

        $this->assertTrue($set->hasAll(['record:read', 'user:profile:read']));
        $this->assertFalse($set->hasAll(['record:read', 'missing']));

        $this->assertTrue($set->hasAny(['missing', 'user:profile:read']));
        $this->assertFalse($set->hasAny(['missing1', 'missing2']));
    }

    public function testPermissionCodeTraitAndGroup(): void
    {
        $permission = TestGroupedPermission::ITEM_READ;

        $this->assertSame('item:read', $permission->identifier());
        $this->assertSame(TestGroup::GENERAL, $permission->group());
    }
}
