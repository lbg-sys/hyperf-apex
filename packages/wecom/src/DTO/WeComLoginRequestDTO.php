<?php

declare(strict_types=1);

namespace Hyperf\Apex\WeCom\DTO;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\DTO\Attribute\FieldDescription;
use Hyperf\Apex\DTO\Attribute\Length;
use Hyperf\Apex\WeCom\Login\WeComLoginService;

/**
 * 用企微授权回调带回的 code 换成员身份。
 *
 * code 与 state 都来自回调 URL 的查询参数，前端原样回传即可。
 * 这里只卡长度上限（防畸形输入），「code 是否还有效」「state 是否是我们签发的」
 * 属于一次性凭据的校验，放在 {@see WeComLoginService} 里做。
 */
final readonly class WeComLoginRequestDTO extends AbstractDTO
{
    public function __construct(
        #[FieldDescription('企业微信授权回调带回的 code，一次性且几分钟内有效')]
        #[Length(min: 1, max: 512)]
        public string $code,
        #[FieldDescription('发起授权时返回的 state，用于防止授权码被重放')]
        #[Length(min: 1, max: 128)]
        public string $state,
        #[FieldDescription('企业别名，多企业配置下指定用哪个企业；不传用默认企业')]
        public ?string $alias = null,
    ) {
    }
}
