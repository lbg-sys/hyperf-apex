# hyperf-apex/wecom

企业微信（WeCom / 微信企业号）组件：**多企业配置**、**进程内共用同一个 EasyWeChat 客户端**、
access_token 缓存与自愈、OAuth 网页授权 / 扫码登录。

底座是 [`w7corp/easywechat`](https://github.com/w7corp/easywechat) 6.x 的 `EasyWeChat\Work\Application`。
本组件只把「多企业 + 凭据缓存 + 错误归一」这三件共性做掉，其余能力（通讯录、消息推送、外部联系人、
回调验签）直接用 EasyWeChat 原生 API，见「拿原生应用实例」一节。

## 安装

```bash
composer require hyperf-apex/wecom
```

发布配置模板：

```bash
php bin/hyperf.php vendor:publish hyperf-apex/wecom --id=config
```

## 配置

`config/autoload/wecom.php`：

```php
return [
    'default' => env('WECOM_ALIAS', 'default'),
    'accounts' => [
        // 键名就是调用方使用的 alias
        'default' => [
            'corp_id' => env('WECOM_CORP_ID', ''),
            'agent_id' => env('WECOM_AGENT_ID', ''),
            'secret' => env('WECOM_SECRET', ''),
            'token' => env('WECOM_TOKEN', ''),        // 回调验签用，只做主动调用可留空
            'aes_key' => env('WECOM_AES_KEY', ''),    // 同上
            'redirect_uri' => env('WECOM_REDIRECT_URI', ''),
        ],
        'sales' => [
            'corp_id' => env('WECOM_SALES_CORP_ID', ''),
            // ...
        ],
    ],
    'timeout' => 5,
    'token_expire_ahead' => 300,
    'cache_prefix' => 'wecom:',
    'state_ttl' => 300,
    'log_channel' => 'outbound',
];
```

三条必须知道的约束：

- **`alias` 会进缓存键**，只允许 `[A-Za-z0-9_.-]`；用其它形态的标识（如带冒号的租户复合键）时，
  请在自定义 `AccountResolver` 里先做归一。
- **缓存必须跨 worker 共享**（Redis 等）。EasyWeChat 默认用文件缓存，多 worker 下每个进程各持一个
  token 会互相顶掉，表现为随机 40014。本组件把 EasyWeChat 的缓存换成应用自己的 PSR-16 缓存
  （`Psr\SimpleCache\CacheInterface`，Hyperf 下即 `config/autoload/cache.php` 的默认存储）。
- **未配置 `corp_id` 的企业等同于不存在**：调用点抛 503（`SERVICE_UNAVAILABLE`），不做启动期拦截 ——
  企业微信只是能力之一，缺配置不该让整个服务起不来；但也绝不「没配就当成登录失败」。

## 快速开始

```php
use Hyperf\Apex\WeCom\Manager\WeComManager;

final class UserSyncService
{
    public function __construct(private readonly WeComManager $wecom)
    {
    }

    public function departmentUsers(int $departmentId, ?string $alias = null): array
    {
        // 成功返回响应体；失败抛 BusinessException（503），errcode 在 $e->getData()['errcode'] 里
        return $this->wecom->api($alias)->get('cgi-bin/user/list', [
            'department_id' => $departmentId,
        ]);
    }

    public function sendText(string $userId, string $content, ?string $alias = null): array
    {
        return $this->wecom->api($alias)->post('cgi-bin/message/send', [
            'touser' => $userId,
            'msgtype' => 'text',
            'agentid' => (int) $this->wecom->account($alias)->agentId,
            'text' => ['content' => $content],
        ]);
    }
}
```

`WeComManager` 的能力：

| 方法 | 用途 |
| :--- | :--- |
| `api(?string $alias)` | **推荐入口**：发 GET/POST 并判定 `errcode`，自带 token 自愈与错误归一 |
| `application(?string $alias)` | 取 `EasyWeChat\Work\Application` 原生实例（上传素材、回调验签、JS-SDK ticket…） |
| `client(?string $alias)` | 取带 access_token 的 HTTP 客户端（EasyWeChat 未封装的能力） |
| `accessToken(?string $alias)` | 取当前 access_token（缓存命中不发请求） |
| `forgetAccessToken(?string $alias)` | 丢掉缓存的 token，下次取时重新换 |
| `account(?string $alias)` | 取企业配置（`WeComAccountDTO`，含密钥，勿外传） |
| `defaultAlias()` | 调用方不指定 alias 时用的企业 |

### access_token 为什么会「被顶掉」，组件怎么处理

企微的 access_token 按企业全局唯一：同一企业在别的系统里重新获取会把这个 token 顶掉。
因此组件做了两层防护：

1. **缓存提前过期**（`token_expire_ahead`，默认 300 秒）：宁可提前换一个新的，也不要在过期边缘
   拿一个可能已被顶掉的；
2. **错误码自愈**：`api()` 拿到 `40014` / `42001` 会丢掉缓存并**重发一次**，偶发被顶掉不会变成
   用户可见的失败。重发意味着 HTTP 选项必须能重复构造，别往里塞一次性的流。

缓存键由本组件指定（`{prefix}access_token:{alias}:{corp_id 摘要}`）。EasyWeChat 默认键是
`work.access_token.{corpId}.{secret}` —— secret 会进 Redis 键名，而键名会被 `KEYS`、监控面板、
慢查询日志原样打出来，等于把密钥另存了一份。

## 企微登录

```php
use Hyperf\Apex\WeCom\DTO\WeComAuthorizeRequestDTO;
use Hyperf\Apex\WeCom\DTO\WeComLoginRequestDTO;
use Hyperf\Apex\WeCom\Login\WeComLoginService;

final class AuthService
{
    public function __construct(private readonly WeComLoginService $login)
    {
    }

    /** 第一步：把两个入口地址与 state 交给前端 */
    public function authorizeUrl(): \Hyperf\Apex\WeCom\DTO\WeComAuthorizeUrlDTO
    {
        return $this->login->buildAuthorizeUrl(new WeComAuthorizeRequestDTO());   // 可传 alias / redirectUri
    }

    /** 第二步：回调带回的 code + state 换成员 userid */
    public function resolveUserId(string $code, string $state): string
    {
        return $this->login->resolveMember(new WeComLoginRequestDTO(code: $code, state: $state))->userId;
    }
}
```

- 返回的 `inAppAuthorizeUrl` 给「企微客户端内打开页面」（静默授权），`qrCodeAuthorizeUrl` 给
  「PC 浏览器扫码」；两者共用同一个 `redirect_uri` 与 `state`。两个地址的域名都必须在企微后台
  配为**可信域名**，否则授权页直接报错，而我们这边看不到任何请求。
- `state` 校验通过即作废，第二次用同一个 `state` 必然失败。它是 128 位随机值，不落库，
  存在应用缓存里（`state_ttl`，默认 300 秒），TTL 就是它的一生。
- **`code` 换 userid 走的就是 `api()` 那条路**：同一个 Application、同一个 access_token 缓存、
  同一套超时与代理设置，没有第二套 HTTP 客户端。
- 只认**企业内部成员**：响应里没有 `userid`（外部联系人拿到的是 `openid`）时按未认证拒绝，
  不会把 openid 当成登录标识。
- 本组件只回答「这个 code 是谁」。会话、令牌、平台账号绑定由应用自己做 —— 能进企业微信只说明
  是同事，不等于应该拥有这个系统的账号。

## 换成数据库 / 配置中心

企业配置要能在后台改，或者要按租户动态取不同企业时，实现 `AccountResolverInterface` 并替换绑定：

```php
use Hyperf\Apex\WeCom\Config\AccountResolverInterface;

// config/autoload/dependencies.php 或自己的 ConfigProvider
return [
    'dependencies' => [
        AccountResolverInterface::class => \App\Module\WeCom\DbAccountResolver::class,
    ],
];
```

`resolve()` 返回 `null` 表示「这个企业不可用」，组件据此抛 503，而不是拿着空配置去发请求。
两个方法都会在每次取客户端时被调用，允许内部缓存，但必须无副作用。

## 运行环境与排障

- **Swoole / Swow 挂钩**：EasyWeChat 底下的 Symfony HttpClient 走 curl，协程下需要
  `SWOOLE_HOOK_NATIVE_CURL`（或 `SWOOLE_HOOK_ALL`）。没挂钩时请求会阻塞事件循环，
  表现为整台机器 QPS 掉到个位数而不是报错。
- **日志**：走 `outbound` 通道（应用未定义该通道时自动退回 `default`），logger 名 `wecom`，
  可用 `log_channel` 改。只记路径、`errcode`、`errmsg`，**不记 query / body** ——
  里面有 code、userid 一类的标识。
- **企业不存在 / 缺 secret**：503 且消息里带 alias。看到 503 先查配置，不要往用户绑定方向查；
  反过来，看到 401 才是「这个 code / 这个人不成立」。
- **授权页直接报错**：先确认可信域名，再确认 `redirect_uri` 与前端实际回调地址一致。

## 变更记录

破坏性变更与新增能力都记在主仓库 `hyperf-apex/apex` 的 [CHANGELOG.md](../../CHANGELOG.md) 里。
