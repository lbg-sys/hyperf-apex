<?php

declare(strict_types=1);

namespace Hyperf\Apex\Notify\DTO;

use Hyperf\Apex\DTO\AbstractDTO;

/**
 * 图片 / 文件的附件内容。
 *
 * `content` 是**原始字节**而不是 base64：编码是各渠道自己的事
 * （企微的 image 要求 base64 + md5，file 要走 multipart 上传），
 * 放在渠道层之外只会让调用方替渠道做协议转换。
 */
final readonly class NotifyAttachmentDTO extends AbstractDTO
{
    public function __construct(
        public string $content,
        /** 文件名，`file` 类型必填——企微用它控制消息里展示的文件名 */
        public string $name = '',
        public string $mimeType = '',
    ) {
    }

    public function size(): int
    {
        return strlen($this->content);
    }
}
