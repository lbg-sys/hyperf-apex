<?php

declare(strict_types=1);

namespace Hyperf\Apex\Notify\DTO;

use Hyperf\Apex\DTO\AbstractDTO;

/**
 * 图文消息的一条图文。
 *
 * 长度不在这里校验：企微对 `title`（128 字节）与 `description`（512 字节）是**自动截断**，
 * 不需要我们先行处理，与 text / markdown 的超长即报错不同。
 */
final readonly class NotifyArticleDTO extends AbstractDTO
{
    public function __construct(
        public string $title,
        /** 点击后跳转的链接，必填 */
        public string $url,
        public string $description = '',
        public ?string $picUrl = null,
    ) {
    }

    /**
     * 从落库的 JSON 还原（告警投递是异步的，图文列表必须能跨请求/跨进程传递）。
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $picUrl = $data['picUrl'] ?? null;

        return new self(
            (string) ($data['title'] ?? ''),
            (string) ($data['url'] ?? ''),
            (string) ($data['description'] ?? ''),
            is_string($picUrl) && $picUrl !== '' ? $picUrl : null,
        );
    }
}
