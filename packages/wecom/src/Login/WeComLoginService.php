<?php

declare(strict_types=1);

namespace Hyperf\Apex\WeCom\Login;

use Hyperf\Apex\WeCom\DTO\WeComAccountDTO;
use Hyperf\Apex\WeCom\DTO\WeComAuthorizeRequestDTO;
use Hyperf\Apex\WeCom\DTO\WeComAuthorizeUrlDTO;
use Hyperf\Apex\WeCom\DTO\WeComLoginPanelDTO;
use Hyperf\Apex\WeCom\DTO\WeComLoginRequestDTO;
use Hyperf\Apex\WeCom\DTO\WeComMemberDTO;
use Hyperf\Apex\WeCom\Exception\WeComException;
use Hyperf\Apex\WeCom\Manager\WeComManager;

/**
 * 企业微信登录：发起授权 → 回调换成员身份。
 *
 * 一次授权有三种入口，共用同一份企业配置、同一个 state、同一条兑换链路：
 * 企微客户端内网页授权（静默）、PC 跳转企微托管扫码页、PC 页内嵌登录面板
 * （`ww.createWWLoginPanel`，见 {@see WeComLoginPanelDTO}）。
 *
 * 换 userid 只有一条路：走业务接口那套出口（见 {@see WeComManager::api()}），
 * 同一个 Application、同一个 access_token 缓存、同一套超时与代理设置，没有第二套 HTTP 客户端。
 *
 * 授权地址与面板参数本身都是纯字符串拼装，不经过 EasyWeChat 的 Socialite：地址形态由企微固定
 * 且与后台「可信域名」配置绑定（客户端内网页授权 / PC 扫码是两个不同域名），
 * 组件沿用已在线上跑通的形态，不依赖第三方 SDK 对地址的拼法。
 *
 * 只覆盖**企业自建应用**（面板参数 login_type = CorpApp）。第三方应用与服务商代开发应用的
 * 授权入口相同，但 code 要走另一套兑换接口（`cgi-bin/service/*`），不在本组件范围内。
 *
 * 本类只回答「这个 code 是谁」：会话、令牌、平台账号绑定都不在它的职责里。
 */
final class WeComLoginService
{
    /** 企业微信客户端内打开的网页授权页：静默授权，用户不看到确认页。 */
    private const IN_APP_AUTHORIZE_URL = 'https://open.weixin.qq.com/connect/oauth2/authorize';

    /** PC 浏览器扫码登录页。 */
    private const QR_CODE_AUTHORIZE_URL = 'https://login.work.weixin.qq.com/wwlogin/sso/login';

    /** 用授权回调的 code 换成员身份（网页授权、扫码登录、登录面板共用这一个接口）。 */
    private const USER_INFO_API = 'cgi-bin/auth/getuserinfo';

    /** 面板的登录类型：自建应用（含服务商代开发）用 CorpApp。 */
    private const LOGIN_TYPE_CORP_APP = 'CorpApp';

    public function __construct(
        private readonly WeComManager $manager,
        private readonly OAuthStateStore $states,
    ) {
    }

    /**
     * 生成一次授权所需的 state 与三个入口（客户端内地址、扫码跳转地址、面板参数）。
     *
     * 先检查配置再签发 state：顺序反过来的话，配置缺失会在缓存里留下一个没人会用的
     * 孤儿 state，只能等 TTL 自己消失；而且用户已经跳到授权页才发现登不进来，体验更差。
     *
     * @throws WeComException 企业未配置或配置不全
     */
    public function buildAuthorizeUrl(WeComAuthorizeRequestDTO $request): WeComAuthorizeUrlDTO
    {
        $account = $this->manager->account($request->alias);
        // 空串按「未覆盖」处理：可选参数从 query 传进来时，前端把空串当默认值是常态。
        $override = $request->redirectUri === null ? '' : trim($request->redirectUri);
        $redirectUri = $override === '' ? $account->redirectUri : $override;

        /*
         * 一次授权链路会用到的凭据必须在这里就位，缺一条就直接失败：
         * - canCallApi（corp_id + secret）：扫码回来的 code 换不出 userid，登录必然走不完；
         * - agent_id + redirect_uri：入口地址与面板参数里各缺一个，入口本身就不成立。
         * 缺了却把入口发出去，用户看到的是「扫了码没用」，排查时还得先想到是配置问题。
         */
        if (! $account->canCallApi() || $account->agentId === '' || $redirectUri === '') {
            throw WeComException::notConfigured($account->alias);
        }

        $state = $this->states->issue($account->alias);

        return new WeComAuthorizeUrlDTO(
            $this->inAppAuthorizeUrl($account, $redirectUri, $state),
            $this->qrCodeAuthorizeUrl($account, $redirectUri, $state),
            new WeComLoginPanelDTO(
                self::LOGIN_TYPE_CORP_APP,
                $account->corpId,
                $account->agentId,
                // 面板参数要求原样传入、不做 URLEncode（与上面两个地址里的 encode 形态不同）。
                $redirectUri,
                $state,
            ),
            $state,
        );
    }

    /**
     * 用回调带回的 code 换取企业成员身份。
     *
     * 先验 state：它不成立时连企微都不必调用，也就不会消耗掉那个 code。
     *
     * 只认企业内部成员：响应里没有 userid 说明调用者不是本企业成员，或不在本应用的可见范围内
     * （此时企微同样返回 errcode = 0）。外部联系人的 openid 对登录没有意义，
     * 不能把它当成一个可用的登录标识放过去。
     *
     * @throws WeComException state 失效、code 失效，或企微不可用
     */
    public function resolveMember(WeComLoginRequestDTO $request): WeComMemberDTO
    {
        $account = $this->manager->account($request->alias);

        if (! $this->states->consume($account->alias, $request->state)) {
            throw WeComException::invalidState();
        }

        $data = $this->manager->api($account->alias)->get(self::USER_INFO_API, ['code' => $request->code]);

        $userId = trim((string) ($data['userid'] ?? ''));
        if ($userId === '') {
            throw WeComException::notAMember();
        }

        return new WeComMemberDTO($account->alias, $userId);
    }

    private function inAppAuthorizeUrl(WeComAccountDTO $account, string $redirectUri, string $state): string
    {
        $query = http_build_query([
            'appid' => $account->corpId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'snsapi_base',
            'state' => $state,
        ]);

        // 结尾的 #wechat_redirect 是企微/微信对这条授权链接的硬性要求，缺失会被拒绝跳转。
        return self::IN_APP_AUTHORIZE_URL . '?' . $query . '#wechat_redirect';
    }

    private function qrCodeAuthorizeUrl(WeComAccountDTO $account, string $redirectUri, string $state): string
    {
        return self::QR_CODE_AUTHORIZE_URL . '?' . http_build_query([
            // 自建应用固定用 CorpApp；服务商代开发场景才走别的取值，本组件不涉及。
            'login_type' => 'CorpApp',
            'appid' => $account->corpId,
            'agentid' => $account->agentId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }
}
