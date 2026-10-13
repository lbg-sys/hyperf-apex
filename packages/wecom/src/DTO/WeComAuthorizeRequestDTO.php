<?php

declare(strict_types=1);

namespace Hyperf\Apex\WeCom\DTO;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\DTO\Attribute\FieldDescription;

/**
 * 生成企微授权地址的入参。
 *
 * 不传 `alias` 时用配置里的默认企业；`redirectUri` 只在少数场景需要覆盖
 * （同一个企业有多套前端域名、各自可信域名不同），一般留空用企业配置里的那个。
 */
final readonly class WeComAuthorizeRequestDTO extends AbstractDTO
{
    public function __construct(
        #[FieldDescription('企业别名，多企业配置下指定用哪个企业；不传用默认企业')]
        public ?string $alias = null,
        #[FieldDescription('覆盖企业配置里的授权回调地址；不传用企业配置')]
        public ?string $redirectUri = null,
    ) {
    }
}
