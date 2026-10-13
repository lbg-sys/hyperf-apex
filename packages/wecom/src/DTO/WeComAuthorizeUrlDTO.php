<?php

declare(strict_types=1);

namespace Hyperf\Apex\WeCom\DTO;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\DTO\Attribute\FieldDescription;

/**
 * 一次授权的全部入口，调用方按场景挑一个用。
 *
 * 三种入口各自只在自己的场景下可用，缺了哪个，那种场景要么打不开、要么拿到一串看不懂的报错：
 * - 企微客户端内打开页面 → inAppAuthorizeUrl（静默授权，用户无感）；
 * - PC 浏览器、跳转到企微托管的扫码页 → qrCodeAuthorizeUrl；
 * - PC 浏览器、在本页内嵌二维码面板（`ww.createWWLoginPanel`）→ loginPanel 的参数。
 *
 * 三种入口共用同一个 redirect_uri 与 state：不管用户从哪条路进来，回调方都能通过 state
 * 认出是同一次授权发起，后端也只有一套 state 校验与 code 兑换（见 WeComLoginService）。
 */
final readonly class WeComAuthorizeUrlDTO extends AbstractDTO
{
    public function __construct(
        #[FieldDescription('企业微信客户端内打开的网页授权地址（静默授权）')]
        public string $inAppAuthorizeUrl,
        #[FieldDescription('PC 浏览器扫码登录地址，需在企业微信客户端内扫码')]
        public string $qrCodeAuthorizeUrl,
        #[FieldDescription('PC 浏览器内嵌登录面板（ww.createWWLoginPanel）的初始化参数')]
        public WeComLoginPanelDTO $loginPanel,
        #[FieldDescription('本次授权的 state，三个入口共用；回调后需连同 code 一起提交给登录接口')]
        public string $state,
    ) {
    }
}
