<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security;

/**
 * 当前请求的身份快照。
 *
 * `tenantId` 与 `agentId` 是两类互斥的数据范围：租户用户带 `tenantId`，代理商用户带 `agentId`，
 * 系统用户两者都为空。业务代码不得靠「哪个不为空」猜身份，一律以 `userType` 为准
 * （见 AuthorizationMiddleware 的 userType 校验）。
 */
final readonly class Principal
{
    public function __construct(
        public int $userId,
        public ?int $tenantId,
        public ?int $agentId,
        public string $userType,
        public string $username,
        public string $sessionId,
        public int $permissionVersion = 1,
    ) {
    }
}
