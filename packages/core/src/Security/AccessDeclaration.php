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
    public const MODE_AND = 'AND';

    public const MODE_OR = 'OR';

    /**
     * @param list<string> $permissions 需要的权限码标识列表
     * @param list<string> $roles 需要的角色标识列表
     * @param null|string $guard 权限码所属的守卫标识，取值由应用自行定义
     * @param string $permissionMode 权限码的校验模式（AND / OR），透传给应用判定
     * @param string $roleMode 角色的校验模式（AND / OR），透传给应用判定
     * @param null|string $roleGuard 角色所属的守卫标识（与权限码分开：同一端点完全可能
     *                               在 admin 体系里要权限码、在 ops 体系里要角色）
     */
    public function __construct(
        public bool $public = false,
        public bool $integration = false,
        public bool $apiKey = false,
        public bool $authenticated = false,
        public array $permissions = [],
        public array $roles = [],
        public ?string $guard = null,
        public string $permissionMode = self::MODE_AND,
        public string $roleMode = self::MODE_AND,
        public ?string $roleGuard = null,
    ) {
    }

    /**
     * 是否声明过任意一种访问规则。
     */
    public function isDeclared(): bool
    {
        return $this->hasAccessDeclaration() || $this->requiresPermission() || $this->roles !== [];
    }

    /**
     * 是否声明了「访问口径」（四类之一）。
     *
     * 与 {@see isDeclared()} 的区别：细粒度要求（权限码 / 角色）不构成口径。
     * 判断「方法级有没有覆盖类级的口径」必须用这个，而不是 isDeclared() ——
     * 否则一个方法级的 #[RequirePermission] 会把类级的 #[Authenticated] 整体顶掉。
     */
    public function hasAccessDeclaration(): bool
    {
        return $this->public || $this->integration || $this->apiKey || $this->authenticated;
    }

    /**
     * 已声明的访问口径名称，按由宽到严排列。
     *
     * 供启动期检查判断「同一个端点声明了多个口径」这种自相矛盾的写法。
     *
     * @return list<string>
     */
    public function declaredAccessModes(): array
    {
        $modes = [];
        if ($this->public) {
            $modes[] = 'PublicAccess';
        }
        if ($this->integration) {
            $modes[] = 'IntegrationAccess';
        }
        if ($this->apiKey) {
            $modes[] = 'ApiKeyAccess';
        }
        if ($this->authenticated) {
            $modes[] = 'Authenticated';
        }

        return $modes;
    }

    /**
     * 与类级声明合并。
     *
     * 两个维度分开处理：
     * - **访问口径** 方法级优先、回退类级 —— 四类口径描述的是「这个端点怎么进来」，
     *   是一组互斥选项，方法级写了就整体以方法级为准；
     * - **细粒度要求**（权限码 / 角色 / guard）取并集 —— 类级是整族的默认要求，
     *   方法级只多不少，合并方向永远不会放松权限。
     */
    public function mergedWith(self $classLevel): self
    {
        $access = $this->hasAccessDeclaration() ? $this : $classLevel;

        return new self(
            public: $access->public,
            integration: $access->integration,
            apiKey: $access->apiKey,
            authenticated: $access->authenticated,
            permissions: array_values(array_unique([...$classLevel->permissions, ...$this->permissions])),
            roles: array_values(array_unique([...$classLevel->roles, ...$this->roles])),
            guard: $this->guard ?? $classLevel->guard,
            roleGuard: $this->roleGuard ?? $classLevel->roleGuard,
            // 模式与方法级声明保持一致；方法级没写权限码时沿用类级的模式。
            permissionMode: $this->permissions === [] ? $classLevel->permissionMode : $this->permissionMode,
            roleMode: $this->roles === [] ? $classLevel->roleMode : $this->roleMode,
        );
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
