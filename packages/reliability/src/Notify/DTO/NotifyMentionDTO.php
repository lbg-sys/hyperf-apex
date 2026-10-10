<?php

declare(strict_types=1);

namespace Hyperf\Apex\Notify\DTO;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\Support\StringList;

/**
 * 需要 @ 的对象。
 *
 * 区分 userid 与手机号而不是给一个扁平字符串列表：企微的 `mentioned_list` 收 userid / `@all`，
 * `mentioned_mobile_list` 收手机号，两者是不同的字段。用扁平列表就得靠「像不像手机号」来猜，
 * 猜错的表现是 @ 不到人且不报错——静默失效。
 */
final readonly class NotifyMentionDTO extends AbstractDTO
{
    /**
     * @param list<string> $userIds 渠道侧的用户标识
     * @param list<string> $mobiles 手机号
     */
    public function __construct(
        public bool $all = false,
        public array $userIds = [],
        public array $mobiles = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return ! $this->all && $this->userIds === [] && $this->mobiles === [];
    }

    /**
     * 从落库的 JSON 还原（告警投递是异步的，@ 名单必须能跨请求/跨进程传递）。
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (bool) ($data['all'] ?? false),
            StringList::of($data['userIds'] ?? []),
            StringList::of($data['mobiles'] ?? []),
        );
    }
}
