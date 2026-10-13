<?php

declare(strict_types=1);

namespace Hyperf\Apex\WeCom\DTO;

use Hyperf\Apex\DTO\AbstractDTO;

/**
 * 一个企业微信企业（一个 corp_id）的运行期配置。
 *
 * 这是组件内部的配置载体，不出现在接口文档里：它是密钥的容器（`secret` / `aesKey`），
 * 只允许在组件内部流转，响应 DTO 与日志里都不能出现。
 *
 * `alias` 是调用方使用的标识（`accounts` 的键名），同一个 corp_id 在不同项目里可以叫不同 alias。
 */
final readonly class WeComAccountDTO extends AbstractDTO
{
    public function __construct(
        public string $alias,
        public string $corpId,
        public string $agentId = '',
        public string $secret = '',
        public string $token = '',
        public string $aesKey = '',
        public string $redirectUri = '',
    ) {
    }

    /**
     * 能否发服务端接口调用（换 token 只需要 corp_id + secret）。
     */
    public function canCallApi(): bool
    {
        return $this->corpId !== '' && $this->secret !== '';
    }
}
