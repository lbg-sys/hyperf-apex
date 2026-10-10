<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbox\Enum;

use Hyperf\Apex\Enum\Interface\EnumInterface;
use Hyperf\Apex\Enum\Traits\EnumTrait;
use Hyperf\Constants\Annotation\Constants;
use Hyperf\Constants\Annotation\Message;

#[Constants]
enum OutboxHandleOutcome: string implements EnumInterface
{
    use EnumTrait;

    #[Message('执行成功')]
    case SUCCESS = 'success';

    #[Message('可重试失败')]
    case RETRYABLE = 'retryable';

    #[Message('永久失败')]
    case PERMANENT = 'permanent';
}
