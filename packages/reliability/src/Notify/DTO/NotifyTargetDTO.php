<?php

declare(strict_types=1);

namespace Hyperf\Apex\Notify\DTO;

use Hyperf\Apex\DTO\AbstractDTO;

/**
 * 渠道无关的接收目标。
 *
 * 由调用方从自己的渠道配置投影而来——渠道层不碰数据库，
 * 因此这里只有「怎么连上去」的参数，没有 tenant / 配置 ID 之类的业务概念。
 */
final readonly class NotifyTargetDTO extends AbstractDTO
{
    public function __construct(
        public string $webhookUrl,
        public int $timeoutSeconds = 10,
        public int $connectTimeoutSeconds = 5,
        public bool $verifyTls = true,
    ) {
    }
}
