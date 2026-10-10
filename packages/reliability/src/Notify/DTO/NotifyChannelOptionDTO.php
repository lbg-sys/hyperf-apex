<?php

declare(strict_types=1);

namespace Hyperf\Apex\Notify\DTO;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\DTO\Attribute\FieldDescription;

/** 管理端渠道类型下拉项。 */
final readonly class NotifyChannelOptionDTO extends AbstractDTO
{
    public function __construct(
        #[FieldDescription('渠道编码，创建/更新渠道时传该值')]
        public string $code,
        #[FieldDescription('渠道名称')]
        public string $name,
    ) {
    }
}
