<?php

declare(strict_types=1);

namespace Hyperf\Apex\WeCom\DTO;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\DTO\Attribute\FieldDescription;

/**
 * 一次企微授权换来的企业成员身份。
 *
 * 只有企业内部成员才有 `userId`；外部联系人（客户）拿到的是 openid，对登录没有意义，
 * 因此这里不提供 openid 字段 —— 那种情况在解析阶段就按「未认证」拒绝掉了。
 */
final readonly class WeComMemberDTO extends AbstractDTO
{
    public function __construct(
        #[FieldDescription('企业别名，标识这个成员属于哪个企业')]
        public string $alias,
        #[FieldDescription('企业微信成员 userid，企业内唯一')]
        public string $userId,
    ) {
    }
}
