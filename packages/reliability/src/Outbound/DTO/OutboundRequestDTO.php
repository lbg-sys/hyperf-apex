<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbound\DTO;

use Hyperf\Apex\Outbound\OutboundRecorder;

/**
 * 出站 HTTP 请求描述。
 *
 * 只描述「发什么」，不描述「怎么算成功」：状态码与响应体的语义留给各第三方客户端解释。
 */
final readonly class OutboundRequestDTO
{
    /**
     * @param string $purpose 调用用途，只用于日志定位
     * @param string $method 请求方法，项目内只使用 GET / POST
     * @param string $url 完整请求地址
     * @param array<string, string> $headers 业务请求头
     * @param array<string, mixed> $json 请求体
     * @param int $timeoutSeconds 整次请求超时（秒）
     * @param int $connectTimeoutSeconds 连接超时（秒）
     * @param bool $verifyTls 是否校验 TLS 证书
     * @param string $target 出站目标标识符，用于度量指标分类
     */
    public function __construct(
        public string $purpose,
        public string $method,
        public string $url,
        public array $headers = [],
        public array $json = [],
        public int $timeoutSeconds = 10,
        public int $connectTimeoutSeconds = 5,
        public bool $verifyTls = true,
        public string $target = OutboundRecorder::TARGET_HTTP,
    ) {
    }

    /** 发送 JSON 请求体：空数组也要发 {}，第三方普遍按 object 校验请求体 */
    public function sendsBody(): bool
    {
        return $this->method === 'POST' || $this->json !== [];
    }
}
