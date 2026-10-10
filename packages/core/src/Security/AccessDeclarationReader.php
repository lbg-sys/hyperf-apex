<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security;

use Hyperf\Apex\Security\Attribute\ApiKeyAccess;
use Hyperf\Apex\Security\Attribute\Authenticated;
use Hyperf\Apex\Security\Attribute\IntegrationAccess;
use Hyperf\Apex\Security\Attribute\PublicAccess;
use Hyperf\Apex\Security\Attribute\RequireAgentPermission;
use Hyperf\Apex\Security\Attribute\RequirePermission;
use Hyperf\Apex\Security\Attribute\RequireRole;
use Hyperf\Apex\Security\Attribute\RequireSystemPermission;
use ReflectionAttribute;
use ReflectionMethod;

/**
 * 读取控制器方法和类上的访问声明。
 *
 * 方法级声明优先；方法未声明任何规则时回退到类级声明。
 */
final class AccessDeclarationReader
{
    public function read(ReflectionMethod $method): AccessDeclaration
    {
        $declaration = $this->collect($method->getAttributes());
        if ($declaration->isDeclared()) {
            return $declaration;
        }

        return $this->collect($method->getDeclaringClass()->getAttributes());
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
        $tenantCodes = [];
        $systemCodes = [];
        $agentCodes = [];
        $guard = null;

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
                        if ($guard === 'system') {
                            $systemCodes = array_merge($systemCodes, $instance->permissions);
                        } elseif ($guard === 'agent') {
                            $agentCodes = array_merge($agentCodes, $instance->permissions);
                        } else {
                            $tenantCodes = array_merge($tenantCodes, $instance->permissions);
                        }
                    } else {
                        $tenantCodes = array_merge($tenantCodes, $instance->permissions);
                    }
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
                    break;
                case RequireSystemPermission::class:
                    /** @var RequireSystemPermission $instance */
                    $instance = $attribute->newInstance();
                    foreach ($instance->permissions as $p) {
                        $permissions[] = $p;
                        $systemCodes[] = $p;
                    }
                    $guard = 'system';
                    break;
                case RequireAgentPermission::class:
                    /** @var RequireAgentPermission $instance */
                    $instance = $attribute->newInstance();
                    foreach ($instance->permissions as $p) {
                        $permissions[] = $p;
                        $agentCodes[] = $p;
                    }
                    $guard = 'agent';
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
            tenantCodes: array_values(array_unique($tenantCodes)),
            systemCodes: array_values(array_unique($systemCodes)),
            agentCodes: array_values(array_unique($agentCodes)),
        );
    }
}
