<?php

declare(strict_types=1);

namespace Hyperf\Apex\Enum;

use Hyperf\Apex\Enum\Interface\EnumInterface;
use Hyperf\Apex\Enum\Traits\EnumTrait;
use Hyperf\Constants\Annotation\Constants;
use Hyperf\Constants\Annotation\Message;

/**
 * 通知渠道编码。
 *
 * 与 `NotifyChannelInterface::code()` 一一对应，是渠道适配器的注册键，
 * 也是 `alert_channels.channel_code` 的唯一取值来源。
 *
 * 只收录「已有实现类」的渠道：本枚举会进管理端下拉与 OpenAPI enum，
 * 放一个没有实现的 case，等于让管理员能选到一个必然失败的渠道。
 */
#[Constants]
enum NotifyChannelCode: string implements EnumInterface
{
    use EnumTrait;

    #[Message('企业微信群机器人')]
    case WECOM_ROBOT = 'wecom_robot';
}
