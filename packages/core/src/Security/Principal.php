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

    /**
     * 读取一条应用自定义声明；键不存在或值为 null 时返回默认值。
     *
     * `$default` 的类型即返回值类型——这是组件能给出的唯一类型提示，
     * claims 里实际存入的值由应用保证与 `$default` 同类型，组件不做运行时校验。
     *
     * @template T
     * @param T $default
     * @return T
     */
    public function claim(string $key, mixed $default = null): mixed
    {
        return $this->claims[$key] ?? $default;
    }
}
