<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security;

/**
 * 当前请求的身份快照。
 *
 * 只承载与身份识别直接相关的通用维度；应用自己的数据范围、组织、渠道等概念一律放进
 * `$claims`，组件不解释其中任何键，也不替应用定义「一个用户属于什么」。
 */
final readonly class Principal
{
    /**
     * @param array<string, mixed> $claims 应用自定义的身份声明，键与含义均由应用决定
     */
    public function __construct(
        public int $userId,
        public string $userType,
        public string $username,
        public string $sessionId,
        public int $permissionVersion = 1,
        public array $claims = [],
    ) {
    }
}
