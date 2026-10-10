<?php

declare(strict_types=1);

namespace Hyperf\Apex\Notify\Enum;

use Hyperf\Apex\Enum\Interface\EnumInterface;
use Hyperf\Apex\Enum\Traits\EnumTrait;
use Hyperf\Constants\Annotation\Constants;
use Hyperf\Constants\Annotation\Message;

/**
 * 单次渠道发送的结果。
 *
 * 刻意不是「成功 / 失败」二元：**被限流必须独立于失败**。
 * 把限流算进可重试失败，会让限流吃掉有限的重试次数，
 * 最终把一批根本没发出去的告警标成「重试耗尽」——
 * 告警一条没送达，系统却报告是重试次数用完了。
 *
 * | 结果 | 调用方处理 | 消耗重试次数 |
 * | --- | --- | --- |
 * | SUCCESS | 终态 | — |
 * | THROTTLED | 推后 next_retry_time，等限流恢复 | 否 |
 * | RETRYABLE | attempt_count++ 后退避重试 | 是 |
 * | PERMANENT | 立即终态 | — |
 */
#[Constants]
enum NotifyOutcome: string implements EnumInterface
{
    use EnumTrait;

    #[Message('发送成功')]
    case SUCCESS = 'success';

    #[Message('被渠道限流')]
    case THROTTLED = 'throttled';

    #[Message('可重试失败')]
    case RETRYABLE = 'retryable';

    #[Message('不可重试失败')]
    case PERMANENT = 'permanent';
}
