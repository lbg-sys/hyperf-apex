<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbound\DTO;

/**
 * 出站 HTTP 响应。
 *
 * `decoded` 只在响应体是合法 JSON 对象/数组时非 null，取值一律走 {@see value()}：
 * 各平台的错误信封各不相同（`error.message` / `msg` / `errmsg` / `detail`…），
 * 把「字段长什么样」的判断收敛到这一层，业务客户端只关心自己那份契约。
 */
final readonly class OutboundResponseDTO
{
    /** 错误信息兜底长度：解析不出错误结构时截取原始响应体 */
    public const SUMMARY_LIMIT = 512;

    /**
     * @param string $purpose 与请求一致的调用用途，用于日志定位
     * @param int $statusCode HTTP 状态码
     * @param string $body 响应体（已按留痕上限截断）
     * @param null|array<array-key, mixed> $decoded 响应体的 JSON 解析结果；不是合法 JSON 时为 null
     * @param float $durationMs 请求耗时（毫秒）
     */
    public function __construct(
        public string $purpose,
        public int $statusCode,
        public string $body,
        public ?array $decoded,
        public float $durationMs,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * 按点号路径取值，例 `value('error.code')`。结构不符或响应体不是 JSON 时返回默认值。
     */
    public function value(string $path, mixed $default = null): mixed
    {
        $current = $this->decoded;
        if ($current === null) {
            return $default;
        }

        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    public function errorMessage(): string
    {
        foreach (['error.message', 'error.detail', 'message', 'msg', 'errmsg', 'error_msg', 'detail'] as $path) {
            $value = $this->value($path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $this->body === '' ? '响应内容为空' : $this->summary();
    }

    /** 日志用的响应体摘要 */
    public function summary(int $limit = self::SUMMARY_LIMIT): string
    {
        return mb_substr($this->body, 0, max(1, $limit));
    }
}
