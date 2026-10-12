<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Security;

use Hyperf\Apex\Enum\Traits\EnumTrait;
use Hyperf\Apex\Enum\Traits\PermissionCodeTrait;
use Hyperf\Apex\Security\AccessDeclaration;
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
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** 类级口径 + 方法级细粒度要求：两者必须共存。 */
#[Authenticated]
class InheritedAccessController
{
    #[RequirePermission('record:read')]
    public function permissionOnly(): void
    {
    }

    #[RequirePermission(['record:read', 'user:create'], mode: 'OR')]
    public function anyOfPermissions(): void
    {
    }

    #[RequirePermission('record:read', guard: 'admin')]
    #[RequireRole('operator', guard: 'ops')]
    public function dualGuards(): void
    {
    }
}

/** 口径声明在父类上，动作写在子类里。 */
#[Authenticated]
class ParentAuthenticatedController
{
}

class ChildWithoutDeclarationController extends ParentAuthenticatedController
{
    public function inherited(): void
    {
    }
}

/** 同时声明两个口径：启动期检查应当报错。 */
#[PublicAccess]
#[Authenticated]
class ConflictingAccessController
{
    public function both(): void
    {
    }
}

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

    public function testEmptyPermissionCodeIsRejectedAtConstruction(): void
    {
        // 空权限码会让 isDeclared() / requiresPermission() 为真，却没有任何主体能持有它 ——
        // 表现是「这个接口对所有请求恒拒绝」，而日志与响应里都看不出原因。
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('权限码不能为空');

        new RequirePermission('');
    }

    public function testBlankPermissionInsideArrayIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RequirePermission(['record:read', '   ']);
    }

    public function testMethodLevelRequirementKeepsClassLevelAccessMode(): void
    {
        // 方法级的细粒度要求不能让类级的访问口径整体失效：丢掉 authenticated 之后，
        // 「这个端点要不要登录」的消费者会直接放行。
        $declaration = $this->declaration(InheritedAccessController::class, 'permissionOnly');

        $this->assertTrue($declaration->authenticated);
        $this->assertSame(['record:read'], $declaration->requiredCodeValues());
    }

    public function testPermissionModeIsPassedThrough(): void
    {
        // mode 此前只是 Attribute 上的死参数：声明 OR 而判定处拿不到，最终按 AND 执行（静默收紧）。
        $declaration = $this->declaration(InheritedAccessController::class, 'anyOfPermissions');

        $this->assertSame(AccessDeclaration::MODE_OR, $declaration->permissionMode);
    }

    public function testClassLevelDeclarationIsFoundUpTheInheritanceChain(): void
    {
        // 类上的 Attribute 不被继承：动作写在子类、口径声明在父类时，
        // 只看声明类会让它静默失效，端点变成「查不到任何声明」。
        $declaration = $this->declaration(ChildWithoutDeclarationController::class, 'inherited');

        $this->assertTrue($declaration->authenticated);
        $this->assertTrue($declaration->isDeclared());
    }

    public function testConflictingAccessModesAreVisibleToTheChecker(): void
    {
        $declaration = $this->declaration(ConflictingAccessController::class, 'both');

        // 两个口径同时为真时「到底怎么进来」没有唯一答案，启动期检查据此报错。
        $this->assertSame(['PublicAccess', 'Authenticated'], $declaration->declaredAccessModes());
    }

    public function testEmptyRoleIsRejectedAtConstruction(): void
    {
        // 与 #[RequirePermission] 同一判据：空角色标识会让 isDeclared() 为真，
        // 却没有任何主体能持有它（表现为该接口对所有请求恒拒绝且看不出原因）。
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('角色标识不能为空');

        new RequireRole('');
    }

    public function testPermissionCodesAreAlwaysStrings(): void
    {
        // 形如十进制整数的权限码会被 PHP 规整成 int 键，而 codes() 的契约是 list<string>。
        $set = new PermissionSet(['123', 'record:read']);

        $this->assertSame(['123', 'record:read'], $set->codes());
        $this->assertTrue($set->has('123'));
    }

    public function testPermissionCodeTraitAndGroup(): void
    {
        $permission = TestGroupedPermission::ITEM_READ;

        $this->assertSame('item:read', $permission->identifier());
        $this->assertSame(TestGroup::GENERAL, $permission->group());
    }

    public function testPermissionAndRoleGuardsAreKeptApart(): void
    {
        // 权限码与角色可以属于不同的权限体系：此前共用一个字段，后写的那一个会把先写的顶掉，
        // 「admin 体系要权限码 + ops 体系要角色」这种声明会丢一半。
        $declaration = $this->declaration(InheritedAccessController::class, 'dualGuards');

        $this->assertSame('admin', $declaration->guard);
        $this->assertSame('ops', $declaration->roleGuard);
    }

    private function declaration(string $class, string $method): AccessDeclaration
    {
        return (new AccessDeclarationReader())->read(new ReflectionMethod($class, $method));
    }
}
