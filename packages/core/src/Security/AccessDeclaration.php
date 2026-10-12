<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security;

/**
 * 接口的访问声明，由控制器上的 Attribute 解析而来。
 *
 * 描述访问入口凭证策略（公开 / 集成凭证 / API 密钥 / 登录鉴权）以及细粒度权限码和角色要求。
 * 权限码只是不透明的字符串标识，组件不预设任何命名空间或层级含义；`$guard` 供应用标注
 * 自己区分的多套权限体系，组件原样透传。
 */
final readonly class AccessDeclaration
{
    /**
     * @param list<string> $permissions 需要的权限码标识列表
     * @param list<string> $roles 需要的角色标识列表
     * @param null|string $guard 权限所属的守卫标识，取值由应用自行定义
     */
    public function __construct(
        public bool $public = false,
        public bool $integration = false,
        public bool $apiKey = false,
        public bool $authenticated = false,
        public array $permissions = [],
        public array $roles = [],
        public ?string $guard = null,
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
            || $this->roles !== [];
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
        return $this->permissions !== [];
    }

    /**
     * 需要校验的权限码字符串列表。
     *
     * @return list<string>
     */
    public function requiredCodeValues(): array
    {
        return array_values(array_unique($this->permissions));
    }
}
