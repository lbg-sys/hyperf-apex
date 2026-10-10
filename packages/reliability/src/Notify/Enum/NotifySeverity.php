<?php

declare(strict_types=1);

namespace Hyperf\Apex\Notify\Enum;

use Hyperf\Apex\Annotation\Option;
use Hyperf\Apex\Enum\Interface\EnumInterface;
use Hyperf\Apex\Enum\Traits\EnumTrait;
use Hyperf\Constants\Annotation\Constants;
use Hyperf\Constants\Annotation\Message;

/**
 * 告警级别。
 *
 * 放在渠道层而不是告警模块，是因为它的唯一用途是「决定这条消息怎么呈现」
 * （前缀文案、颜色），属于渠道无关的消息体契约的一部分；
 * 告警模块直接复用本枚举作为 `alert_events.severity` 的取值，不再另建一套。
 */
#[Constants]
#[Option('alert_severity')]
enum NotifySeverity: string implements EnumInterface
{
    use EnumTrait;

    #[Message('提示')]
    case INFO = 'info';

    #[Message('警告')]
    case WARNING = 'warning';

    #[Message('严重')]
    case CRITICAL = 'critical';

    /**
     * 级别权重，用于比较严重程度（规则阈值过滤、汇总消息里的排序）。
     *
     * 刻意不依赖枚举声明顺序：`cases()` 的顺序会被「往中间插一个新级别」这种
     * 与排序无关的改动改变，用它做数值比较等于把业务语义绑在代码排版上。
     * 步长取 10 是给将来插入中间级别留的余量。
     */
    public function weight(): int
    {
        return match ($this) {
            self::INFO => 10,
            self::WARNING => 20,
            self::CRITICAL => 30,
        };
    }

    /** 是否达到（不低于）某个级别的门槛 */
    public function meets(self $threshold): bool
    {
        return $this->weight() >= $threshold->weight();
    }
}
