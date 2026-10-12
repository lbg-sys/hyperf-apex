<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security;

use Hyperf\Apex\Security\Attribute\ApiKeyAccess;
use Hyperf\Apex\Security\Attribute\Authenticated;
use Hyperf\Apex\Security\Attribute\IntegrationAccess;
use Hyperf\Apex\Security\Attribute\PublicAccess;
use Hyperf\Apex\Security\Attribute\RequirePermission;
use Hyperf\Apex\Security\Attribute\RequireRole;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * 读取控制器方法和类上的访问声明。
 *
 * 两个维度分开合并（详见 {@see AccessDeclaration::mergedWith()}）：
 * 访问口径方法级优先、回退类级；细粒度要求（权限码 / 角色）取方法级与类级的并集。
 */
final class AccessDeclarationReader
{
    public function read(ReflectionMethod $method): AccessDeclaration
    {
        return $this->collect($method->getAttributes())
            ->mergedWith($this->collect($this->classAttributes($method->getDeclaringClass())));
    }

    /**
     * 类级声明：沿继承链向上收集。
     *
     * 类上的 Attribute 不会被继承，而控制器常把口径声明在抽象基类上、动作写在子类里 ——
     * 只看 `getDeclaringClass()` 会让这类声明静默失效，端点变成「查不到任何声明」。
     * 父类在前、子类在后：同名维度由子类覆盖。
     *
     * @param ReflectionClass<object> $class
     * @return list<ReflectionAttribute<object>>
     */
    private function classAttributes(ReflectionClass $class): array
    {
        $chain = [];
        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            $chain[] = $current;
        }

        $attributes = [];
        foreach (array_reverse($chain) as $current) {
            $attributes = [...$attributes, ...$current->getAttributes()];
        }

        return $attributes;
    }

    /** @param list<ReflectionAttribute<object>> $attributes */
    private function collect(array $attributes): AccessDeclaration
    {
        $public = false;
        $integration = false;
        $apiKey = false;
        $authenticated = false;
        $permissions = [];
        $roles = [];
        $guard = null;
        $permissionMode = AccessDeclaration::MODE_AND;
        $roleMode = AccessDeclaration::MODE_AND;

        foreach ($attributes as $attribute) {
            switch ($attribute->getName()) {
                case PublicAccess::class:
                    $public = true;
                    break;
                case IntegrationAccess::class:
                    $integration = true;
                    break;
                case ApiKeyAccess::class:
                    $apiKey = true;
                    break;
                case Authenticated::class:
                    $authenticated = true;
                    break;
                case RequirePermission::class:
                    /** @var RequirePermission $instance */
                    $instance = $attribute->newInstance();
                    foreach ($instance->permissions as $p) {
                        $permissions[] = $p;
                    }
                    if ($instance->guard !== null) {
                        $guard = $instance->guard;
                    }
                    // 模式要真的传下去：此前它只是 Attribute 上的一个死参数，
                    // 声明 OR 而判定处拿不到，最终会按 AND 执行（静默收紧）。
                    $permissionMode = $instance->mode;
                    break;
                case RequireRole::class:
                    /** @var RequireRole $instance */
                    $instance = $attribute->newInstance();
                    foreach ($instance->roles as $r) {
                        $roles[] = $r;
                    }
                    if ($instance->guard !== null) {
                        $guard = $instance->guard;
                    }
                    $roleMode = $instance->mode;
                    break;
            }
        }

        return new AccessDeclaration(
            public: $public,
            integration: $integration,
            apiKey: $apiKey,
            authenticated: $authenticated,
            permissions: array_values(array_unique($permissions)),
            roles: array_values(array_unique($roles)),
            guard: $guard,
            permissionMode: $permissionMode,
            roleMode: $roleMode,
        );
    }
}
