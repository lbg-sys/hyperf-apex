<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbox\Enum;

use Hyperf\Apex\Enum\Interface\EnumInterface;
use Hyperf\Apex\Enum\Traits\EnumTrait;
use Hyperf\Constants\Annotation\Constants;
use Hyperf\Constants\Annotation\Message;

#[Constants]
enum OutboxEventStatus: string implements EnumInterface
{
    use EnumTrait;

    #[Message('待执行')]
    case PENDING = 'pending';

    #[Message('执行中')]
    case PROCESSING = 'processing';

    #[Message('执行成功')]
    case SUCCEEDED = 'succeeded';

    #[Message('永久失败')]
    case FAILED = 'failed';

    #[Message('死信')]
    case DEAD_LETTER = 'dead_letter';

    /**
     * 是否允许人工重放。
     *
     * 只有「已经停下来的失败」可以重放：`pending` / `processing` 还在投递流程里，
     * 重放等于让同一条事件被投递两次；`succeeded` 已经投递过，重放会重复产生下游副作用。
     */
    public function canReplay(): bool
    {
        return $this === self::FAILED || $this === self::DEAD_LETTER;
    }
}
