<?php

declare(strict_types=1);

use function Hyperf\Support\env;

/*
 * 企业微信（自建应用）配置。
 *
 * 支持多企业：`accounts` 的键名就是调用方使用的 alias。
 *
 *     $wecom->api('sales')->post('cgi-bin/message/send', [...]);
 *     $wecom->api()->get('cgi-bin/user/get', ['userid' => 'zhangsan']);   // 用 default 指向的那个企业
 *
 * alias 会直接进入缓存键，只允许 [A-Za-z0-9_.-]；用其它形态的标识（如带冒号的租户复合键）时，
 * 请在自定义的 AccountResolver 里先做归一。
 *
 * secret / aes_key / token 都是密钥，只能来自环境变量，不得写进仓库。
 *
 * 未配置 corp_id 的企业等同于不存在：调用点抛 503（SERVICE_UNAVAILABLE），不会在启动期拦截 ——
 * 企业微信只是登录与消息能力之一，缺配置不该让整个服务起不来；但也绝不能「没配就当成登录失败」，
 * 那会把排查方向引到用户绑定上去。
 */
return [
    // 调用方不显式指定 alias 时用哪个企业（accounts 的键名）。
    'default' => (string) env('WECOM_ALIAS', 'default'),

    'accounts' => [
        'default' => [
            // 企业 ID，企微管理后台「我的企业」页可取。
            'corp_id' => (string) env('WECOM_CORP_ID', ''),

            // 自建应用 ID：拼网页授权地址与扫码登录地址时必填。
            'agent_id' => (string) env('WECOM_AGENT_ID', ''),

            // 自建应用 Secret：换 access_token 用。属于密钥。
            'secret' => (string) env('WECOM_SECRET', ''),

            /*
             * 回调（接收企微推送）用的 Token 与 EncodingAESKey，取自自建应用的「接收消息」设置。
             * 只做主动调用时留空即可；留空时任何试图取回调服务端的行为都会在配置校验期失败。
             */
            'token' => (string) env('WECOM_TOKEN', ''),
            'aes_key' => (string) env('WECOM_AES_KEY', ''),

            /*
             * 授权回调地址（网页授权 / 扫码登录共用一个）。
             * 该地址的域名必须已在企微管理后台配置为可信域名，否则授权页会直接报错，
             * 而我们这边看不到任何请求 —— 排障时先确认这一条。
             */
            'redirect_uri' => (string) env('WECOM_REDIRECT_URI', ''),
        ],
    ],

    /*
     * 出站超时（秒）。取小数而非整数：企微接口偶发拖到数秒，而默认无超时会把请求协程一直挂住，
     * 最终表现为接口整体不可用。
     */
    'timeout' => (float) env('WECOM_TIMEOUT', 5),

    /*
     * access_token 缓存预留的提前量（秒）。
     *
     * 企微的 access_token 按企业全局唯一：同一企业在别的系统里重新获取会把这个 token 顶掉。
     * 缓存必须留出余量 —— 宁可提前换一个新的，也不要在过期边缘拿一个可能已被顶掉的。
     * 余量之外的漏网情况（还没过期就被顶掉）由「错误码回退重取」兜住，见 WeComApiClient。
     */
    'token_expire_ahead' => (int) env('WECOM_TOKEN_EXPIRE_AHEAD', 300),

    /*
     * access_token 与 OAuth state 的缓存键前缀，落在应用的默认缓存存储上。
     *
     * 该存储必须跨 worker 共享（Redis 等）：每个 worker 各持一个 token 时会互相顶掉，
     * 表现为随机出现「access_token 无效」。与其它项目共用 Redis 实例时，前缀必须唯一。
     */
    'cache_prefix' => (string) env('WECOM_CACHE_PREFIX', 'wecom:'),

    // OAuth state 有效期（秒）：授权链路通常几十秒内走完，给 5 分钟足够，也限制重放窗口。
    'state_ttl' => (int) env('WECOM_STATE_TTL', 300),

    // 出站日志通道；应用没定义该通道时自动退回 default（见 LoggerChannels）。
    'log_channel' => (string) env('WECOM_LOG_CHANNEL', 'outbound'),
];
