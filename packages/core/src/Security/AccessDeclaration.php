<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security;

/**
 * 接口的访问声明，由控制器上的 Attribute 解析而来。
 *
 * 描述访问入口凭证策略（公开 / 集成凭证 / API 密钥 / 登录鉴权）以及细粒度权限码和角色要求。
 */
final readonly class AccessDeclaration
{
    /**
     * @param list<string> $permissions 需要的权限码标识列表
     * @param list<string> $roles 需要的角色标识列表
     * @param list<string> $tenantCodes 兼容字段：租户端权限码列表
     * @param list<string> $systemCodes 兼容字段：系统端权限码列表
     * @param list<string> $agentCodes 兼容字段：代理端权限码列表
     */
    public function __construct(
        public bool $public = false,
        public bool $integration = false,
        public bool $apiKey = false,
        public bool $authenticated = false,
        public array $permissions = [],
        public array $roles = [],
        public ?string $guard = null,
        public array $tenantCodes = [],
        public array $systemCodes = [],
        public array $agentCodes = [],
    ) {
    }

    /**
     * 是否声明过任意一种访问规则。
     */
    public function isDeclared(): bool
    {
        return $this->public
            || $this->integration
            || $this->apiKey
            || $this->authenticated
            || $this->permissions !== []
            || $this->roles !== []
            || $this->tenantCodes !== []
            || $this->systemCodes !== []
            || $this->agentCodes !== [];
    }

    /**
     * 是否声明了互相矛盾的多端权限体系。
     */
    public function isContradictory(): bool
    {
        $namespaces = 0;
        foreach ([$this->tenantCodes !== [], $this->systemCodes !== [], $this->agentCodes !== []] as $declared) {
            $namespaces += $declared ? 1 : 0;
        }

        return $namespaces > 1;
    }

    /**
     * 是否需要跳过用户鉴权。
     */
    public function bypassesUserAuthorization(): bool
    {
        return $this->public || $this->integration;
    }

    /**
     * 是否需要校验权限。
     */
    public function requiresPermission(): bool
    {
        return $this->permissions !== []
            || $this->tenantCodes !== []
            || $this->systemCodes !== []
            || $this->agentCodes !== [];
    }

    /**
     * 声明的权限所属身份/守卫类型，未声明时为 null。
     */
    public function permissionNamespace(): ?string
    {
        if ($this->guard !== null) {
            return $this->guard;
        }

        return match (true) {
            $this->systemCodes !== [] => 'system',
            $this->tenantCodes !== [] => 'tenant',
            $this->agentCodes !== [] => 'agent',
            default => null,
        };
    }

    /**
     * 需要校验的权限码字符串列表。
     *
     * @return list<string>
     */
    public function requiredCodeValues(): array
    {
        $all = [
            ...$this->permissions,
            ...$this->tenantCodes,
            ...$this->systemCodes,
            ...$this->agentCodes,
        ];

        return array_values(array_unique($all));
    }
}
