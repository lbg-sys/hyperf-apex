<?php

declare(strict_types=1);

namespace Hyperf\Apex\Idempotency\Enum;

use Hyperf\Apex\Enum\Interface\EnumInterface;
use Hyperf\Apex\Enum\Traits\EnumTrait;
use Hyperf\Constants\Annotation\Constants;
use Hyperf\Constants\Annotation\Message;

#[Constants]
enum IdempotencyStatus: string implements EnumInterface
{
    use EnumTrait;

    #[Message('处理中')]
    case PROCESSING = 'processing';

    #[Message('执行成功')]
    case SUCCEEDED = 'succeeded';

    #[Message('执行失败')]
    case FAILED = 'failed';
}
