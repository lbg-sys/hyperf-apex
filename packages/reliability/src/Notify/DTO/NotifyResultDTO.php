<?php

declare(strict_types=1);

namespace Hyperf\Apex\Notify\DTO;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\Notify\Enum\NotifyOutcome;

/**
 * 单次渠道发送的归一化结果。
 *
 * 构造函数刻意私有：`THROTTLED` 必须带等待秒数，`PERMANENT` 必须带错误原因，
 * 用工厂方法构造可以把这个约束钉死在类型系统里，而不是靠调用方自觉。
 */
final readonly class NotifyResultDTO extends AbstractDTO
{
    private function __construct(
        public NotifyOutcome $outcome,
        public ?int $retryAfterSeconds = null,
        public ?string $providerMessageId = null,
        public string $errorMessage = '',
        public string $responseSummary = '',
    ) {
    }

    public static function success(?string $providerMessageId = null, string $responseSummary = ''): self
    {
        return new self(NotifyOutcome::SUCCESS, null, $providerMessageId, '', $responseSummary);
    }

    /**
     * 被限流。
     *
     * `$retryAfterSeconds` 允许为空：等待时长是**调用方的策略**而不是渠道的属性。
     * 企微的限流响应不带任何等待提示，渠道只能返回 null，由调用方套用自己的默认值。
     * 只有拿得到确切等待时长的渠道（例如响应里带 `Retry-After`）才填该参数。
     */
    public static function throttled(?int $retryAfterSeconds, string $errorMessage, string $responseSummary = ''): self
    {
        return new self(
            NotifyOutcome::THROTTLED,
            $retryAfterSeconds === null ? null : max(1, $retryAfterSeconds),
            null,
            $errorMessage,
            $responseSummary
        );
    }

    public static function retryable(string $errorMessage, string $responseSummary = ''): self
    {
        return new self(NotifyOutcome::RETRYABLE, null, null, $errorMessage, $responseSummary);
    }

    public static function permanent(string $errorMessage, string $responseSummary = ''): self
    {
        return new self(NotifyOutcome::PERMANENT, null, null, $errorMessage, $responseSummary);
    }

    public function isSuccess(): bool
    {
        return $this->outcome === NotifyOutcome::SUCCESS;
    }

    public function isThrottled(): bool
    {
        return $this->outcome === NotifyOutcome::THROTTLED;
    }
}
