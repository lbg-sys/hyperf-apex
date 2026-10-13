<?php

declare(strict_types=1);

namespace Hyperf\Apex\WeCom\DTO;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\DTO\Attribute\FieldDescription;

/**
 * 企业微信 Web 登录面板（`ww.createWWLoginPanel`）的初始化参数。
 *
 * 面板在前端页面内的 iframe 里渲染二维码，可选「顶层跳转 / 回调 code / 组件内跳转」三种回跳方式，
 * 后端能提供的只有「企业标识 + 回调地址 + state」，因此本 DTO 就是面板的 `params` 对象本身：
 *
 *     ww.createWWLoginPanel({
 *         el: '#login',
 *         params: { login_type, appid, agentid, redirect_uri, state },
 *         redirect_type: 'callback',          // 前端决定
 *         onLoginSuccess({ code }) { ...提交给登录接口... },
 *     })
 *
 * `redirect_type`、`panel_size`、`lang` 是纯前端的交互与展示选择（换不换页、面板多大、什么语言），
 * 服务端不感知，也不下发。
 *
 * 字段名按接口契约用驼峰；面板参数要求蛇形，对应关系写在各自说明里。
 */
final readonly class WeComLoginPanelDTO extends AbstractDTO
{
    public function __construct(
        #[FieldDescription('登录类型（面板参数 login_type），自建应用固定为 CorpApp')]
        public string $loginType,
        #[FieldDescription('企业 CorpID（面板参数 appid）')]
        public string $appId,
        #[FieldDescription('自建应用 AgentID（面板参数 agentid）')]
        public string $agentId,
        #[FieldDescription('登录成功重定向地址（面板参数 redirect_uri），原样传入、不要 URLEncode')]
        public string $redirectUri,
        #[FieldDescription('登录 state（面板参数 state），与本次响应的 state 是同一个值，回调时原样带回')]
        public string $state,
    ) {
    }
}
