<?php

declare(strict_types=1);

namespace Hyperf\Apex\Notify\Contract;

use Hyperf\Apex\Notify\DTO\NotifyMessageDTO;
use Hyperf\Apex\Notify\DTO\NotifyResultDTO;
use Hyperf\Apex\Notify\DTO\NotifyTargetDTO;

/**
 * 通知渠道适配契约。
 *
 * 渠道实现只负责「把与渠道无关的消息体，按该渠道的协议发出去，并把响应归一成 NotifyResultDTO」，
 * 不知道告警、规则、投递记录的存在。新增渠道 = 新增一个实现类 + 在 `notify.channels` 追加类名。
 *
 * 实现方必须遵守的两条：
 * 1. **HTTP 200 不等于发送成功**——业务错误码在响应体里，必须解析；
 * 2. 被限流返回 `NotifyOutcome::THROTTLED` 而不是失败，并给出建议等待秒数。
 */
interface NotifyChannelInterface
{
    /** 渠道编码，取值与 `NotifyChannelCode` 的 backing value 一致 */
    public function code(): string;

    /** 渠道中文名，用于管理端展示 */
    public function name(): string;

    /**
     * 该渠道官方允许的每分钟最大消息数。
     *
     * 调用方的本地限流配置不得超过这个值：本地限流只是「少挨一次渠道拒绝」的前置优化，
     * 配得比渠道上限还高等于没有优化，反而会把一批投递连续推后到触发重试上限。
     */
    public function maxMessagesPerMinute(): int;

    public function send(NotifyTargetDTO $target, NotifyMessageDTO $message): NotifyResultDTO;
}
