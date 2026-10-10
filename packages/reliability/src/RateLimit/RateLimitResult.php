<?php

declare(strict_types=1);

namespace Hyperf\Apex\RateLimit;

/**
 * 一次限流判定的结果。
 *
 * 成功与被拒都会产出剩余额度，用于写 X-RateLimit-* 响应头；被拒时额外给出建议
 * 等待秒数。Redis 不可用时的降级结果同样由本类表达，调用方不需要区分来源。
 */
final readonly class RateLimitResult
{
    public function __construct(
        public bool $allowed,
        /** 桶容量，对应 X-RateLimit-Limit。 */
        public int $limit,
        /** 剩余令牌，对应 X-RateLimit-Remaining。 */
        public int $remaining,
        /** 建议等待秒数，被拒时写入 Retry-After。 */
        public int $retryAfterSeconds,
    ) {
    }

    /**
     * 降级放行：Redis 不可用时按 fail-open 策略放过请求。
     *
     * 额度信息此时不可信，但仍然给出桶容量，避免客户端把它当成「没有限流」。
     */
    public static function degradeOpen(int $limit): self
    {
        return new self(true, $limit, $limit, 0);
    }

    /**
     * 降级拒绝：failure_mode 为 close 时的行为，等一秒后重试即可。
     */
    public static function degradeClosed(int $limit): self
    {
        return new self(false, $limit, 0, 1);
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [
            'X-RateLimit-Limit' => (string) $this->limit,
            'X-RateLimit-Remaining' => (string) $this->remaining,
        ];

        if ($this->allowed || $this->retryAfterSeconds <= 0) {
            return $headers;
        }

        $headers['Retry-After'] = (string) $this->retryAfterSeconds;

        return $headers;
    }
}
