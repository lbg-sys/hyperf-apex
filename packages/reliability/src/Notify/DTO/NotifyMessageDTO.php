<?php

declare(strict_types=1);

namespace Hyperf\Apex\Notify\DTO;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\Notify\Enum\NotifyMessageFormat;
use Hyperf\Apex\Notify\Enum\NotifySeverity;

/**
 * 渠道无关的消息体，按 `format` 取用不同的字段（判别联合）。
 *
 * | format | 用到的字段 |
 * | --- | --- |
 * | `text` / `markdown` / `markdown_v2` | `title`、`content`、`severity`、`linkUrl`、`mentions` |
 * | `news` | `articles` |
 * | `image` / `file` | `attachment` |
 *
 * 只描述「这条消息是什么」，不描述「怎么呈现」：报文怎么拼、颜色怎么上、
 * 超长怎么截，全部由渠道适配器决定，调用方不需要知道渠道能力差异。
 */
final readonly class NotifyMessageDTO extends AbstractDTO
{
    /**
     * @param list<NotifyArticleDTO> $articles 图文列表（`news` 用，1~8 条）
     * @param null|NotifyAttachmentDTO $attachment 附件内容（`image` / `file` 用）
     */
    public function __construct(
        public NotifyMessageFormat $format = NotifyMessageFormat::MARKDOWN,
        public string $title = '',
        public string $content = '',
        public NotifySeverity $severity = NotifySeverity::INFO,
        public ?string $linkUrl = null,
        public ?NotifyMentionDTO $mentions = null,
        public array $articles = [],
        public ?NotifyAttachmentDTO $attachment = null,
    ) {
    }

    public function hasMentions(): bool
    {
        return $this->mentions !== null && ! $this->mentions->isEmpty();
    }
}
