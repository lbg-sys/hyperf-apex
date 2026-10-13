<?php

declare(strict_types=1);

namespace Hyperf\Apex\WeCom\DTO;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\DTO\Attribute\FieldDescription;

/**
 * 企业微信授权地址。
 *
 * 两个地址对应两种不同的入口场景，各自只在其场景下可用，因此一并返回由调用方挑选：
 * - 企微客户端内打开页面走 inAppAuthorizeUrl（静默授权，用户无感）；
 * - PC 浏览器走 qrCodeAuthorizeUrl（弹出扫码）。
 * 只给一个的话，另一种场景下要么打不开、要么拿到一串看不懂的报错。
 *
 * 两个地址共用同一个 redirect_uri 与 state：回调方通过 state 就能认出是同一次授权发起。
 */
final readonly class WeComAuthorizeUrlDTO extends AbstractDTO
{
    public function __construct(
        #[FieldDescription('企业微信客户端内打开的网页授权地址（静默授权）')]
        public string $inAppAuthorizeUrl,
        #[FieldDescription('PC 浏览器扫码登录地址，需在企业微信客户端内扫码')]
        public string $qrCodeAuthorizeUrl,
        #[FieldDescription('本次授权的 state，回调后需连同 code 一起提交给登录接口')]
        public string $state,
    ) {
    }
}
