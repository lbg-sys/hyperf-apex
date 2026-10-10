<?php

declare(strict_types=1);

namespace Hyperf\Apex\Notify\Enum;

use Hyperf\Apex\Enum\Interface\EnumInterface;
use Hyperf\Apex\Enum\Traits\EnumTrait;
use Hyperf\Constants\Annotation\Constants;
use Hyperf\Constants\Annotation\Message;

/**
 * 消息类型（取值与企微的 `msgtype` 对齐）。
 *
 * 官方共八种（另有 voice、template_card），本项目只做这六种：
 * voice 与 template_card 需要素材上传后由人工编排卡片，与告警的实际用途无关。
 *
 * `@` 能力三种类型各不相同，这是最容易做错的一处，见 `supportsInlineMention()`。
 */
#[Constants]
enum NotifyMessageFormat: string implements EnumInterface
{
    use EnumTrait;

    #[Message('文本')]
    case TEXT = 'text';

    #[Message('markdown')]
    case MARKDOWN = 'markdown';

    #[Message('markdown_v2')]
    case MARKDOWN_V2 = 'markdown_v2';

    #[Message('图片')]
    case IMAGE = 'image';

    #[Message('图文')]
    case NEWS = 'news';

    #[Message('文件')]
    case FILE = 'file';

    /** 文本类：有标题/正文，可按长度截断 */
    public function isTextLike(): bool
    {
        return $this === self::TEXT || $this === self::MARKDOWN || $this === self::MARKDOWN_V2;
    }

    /**
     * content 是否支持 `<@userid>` 内联 @ 语法。
     *
     * 官方明确：text / markdown 支持，**markdown_v2 不支持**。
     * 这也是 markdown 不必为了 @ 而牺牲排版的原因。
     */
    public function supportsInlineMention(): bool
    {
        return $this === self::TEXT || $this === self::MARKDOWN;
    }

    /**
     * 是否支持 `mentioned_list` / `mentioned_mobile_list` 字段。
     *
     * 只有 text 有这两个字段。markdown 只能靠内联语法，因此手机号形式和 @all
     * 在 markdown 下无法表达，只能降级为 text。
     */
    public function supportsMentionList(): bool
    {
        return $this === self::TEXT;
    }
}
