<?php

declare(strict_types=1);

namespace Hyperf\Apex\WeCom\Manager;

use EasyWeChat\Kernel\HttpClient\AccessTokenAwareClient;
use EasyWeChat\Work\AccessToken as WorkAccessToken;
use EasyWeChat\Work\Application;
use Hyperf\Apex\Logging\LoggerChannels;
use Hyperf\Apex\WeCom\Api\WeComApiClient;
use Hyperf\Apex\WeCom\Cache\AccessTokenStore;
use Hyperf\Apex\WeCom\Cache\ExpiringCache;
use Hyperf\Apex\WeCom\Config\AccountResolverInterface;
use Hyperf\Apex\WeCom\DTO\WeComAccountDTO;
use Hyperf\Apex\WeCom\Exception\WeComException;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * 企业微信客户端的唯一入口：按 alias 取企业、取 EasyWeChat 应用、取接口出口。
 *
 * 多企业是「同一套代码服务多个 corp_id」，因此所有方法都接受 alias，参数为空时用
 * {@see AccountResolverInterface::defaultAlias()}。企业不存在或没配全时抛 503，
 * 不做「把空配置发出去、让企微回一个看不懂的参数错误」这种把排查方向带偏的事。
 *
 * **实例与客户端都是进程级的**（Hyperf 容器对解析结果做缓存，每个 worker 一份）：
 * 同一个 alias 的 EasyWeChat 应用只建一次，它的 HTTP 客户端与连接池、access_token
 * 缓存句柄都被后续所有调用复用 —— 每次调用新建客户端等于放弃 keep-alive 并放大换 token 的频率。
 * 因此本类不能持有请求级状态（不要往里塞当前用户之类的东西）。
 *
 * 拿到的 Application 是易扩展点：通讯录、消息推送、外部联系人、回调验签都可以直接用
 * `application()` 的原生 API，本组件只把「多企业 + 凭据缓存 + 错误归一」这三件共性做掉。
 */
final class WeComManager
{
    /** 服务端接口基址（EasyWeChat 也以此为默认值，显式写出来是为了让超时与代理的归属一眼可见）。 */
    private const API_BASE_URI = 'https://qyapi.weixin.qq.com/';

    /** @var array<string, Application> */
    private array $applications = [];

    /** @var array<string, WeComApiClient> */
    private array $apiClients = [];

    private readonly ExpiringCache $tokenCache;

    private readonly LoggerInterface $logger;

    private readonly float $timeout;

    public function __construct(
        private readonly AccountResolverInterface $accounts,
        private readonly AccessTokenStore $tokens,
        CacheInterface $cache,
        ConfigInterface $config,
        LoggerFactory $loggerFactory,
    ) {
        $this->tokenCache = new ExpiringCache($cache, max(0, (int) $config->get('wecom.token_expire_ahead', 300)));
        $this->timeout = max(1.0, (float) $config->get('wecom.timeout', 5));
        $this->logger = LoggerChannels::resolve($loggerFactory, 'wecom', (string) $config->get('wecom.log_channel', 'outbound'));
    }

    public function defaultAlias(): string
    {
        return $this->accounts->defaultAlias();
    }

    /**
     * 取企业配置（不入缓存：配置来源允许是数据库，缓存的职责在实现方）。
     *
     * 空字符串按「未指定」处理：可选参数从 query / JSON 传进来时，前端把空串当默认值是常态，
     * 因此 `''` 与 `'  '` 都落到默认企业，而不是报「别名（）未配置」这种看不懂的错误。
     *
     * @throws WeComException 企业不存在或 corp_id 为空
     */
    public function account(?string $alias = null): WeComAccountDTO
    {
        $alias = $alias === null ? null : trim($alias);
        $resolved = ($alias === null || $alias === '') ? $this->defaultAlias() : $alias;
        $account = $this->accounts->resolve($resolved);

        if ($account === null) {
            throw WeComException::notConfigured($resolved);
        }

        return $account;
    }

    /**
     * 取该企业的 EasyWeChat 应用（进程内复用同一个实例）。
     *
     * @throws WeComException corp_id / secret 缺失
     */
    public function application(?string $alias = null): Application
    {
        $account = $this->account($alias);

        return $this->applications[$account->alias] ??= $this->createApplication($account);
    }

    /**
     * 取带 access_token 的 HTTP 客户端。
     *
     * 只推荐给 EasyWeChat 未封装的能力（上传媒体、下载临时素材、通信录的批量接口）；
     * 常规的 GET/POST + errcode 判定请走 {@see self::api()}，那条路自带 token 自愈与错误归一。
     */
    public function client(?string $alias = null): AccessTokenAwareClient
    {
        return $this->application($alias)->getClient();
    }

    /**
     * 取该企业的接口出口（进程内复用；同一个 alias 共用一个 access_token 缓存）。
     */
    public function api(?string $alias = null): WeComApiClient
    {
        $account = $this->account($alias);

        return $this->apiClients[$account->alias] ??= new WeComApiClient(
            $this->application($account->alias)->getClient(),
            $this->tokens,
            $account,
            $this->logger,
        );
    }

    /**
     * 取该企业当前的 access_token（缓存命中就不发请求）。
     *
     * @throws WeComException 配置缺失或企微不可用
     */
    public function accessToken(?string $alias = null): string
    {
        return $this->application($alias)->getAccessToken()->getToken();
    }

    /**
     * 丢掉缓存里的 access_token，下次取 token 时重新换一个。
     *
     * 给「绕过本组件直接调企微接口、又拿到 40014 / 42001」的调用点一个自愈出口；
     * 走 {@see self::api()} 的调用不需要手动调它。
     */
    public function forgetAccessToken(?string $alias = null): void
    {
        $account = $this->account($alias);
        $this->tokens->forget($account->alias, $account->corpId);
    }

    private function createApplication(WeComAccountDTO $account): Application
    {
        if (! $account->canCallApi()) {
            throw WeComException::notConfigured($account->alias);
        }

        $application = new Application([
            'corp_id' => $account->corpId,
            'agent_id' => $account->agentId,
            'secret' => $account->secret,
            // 回调（接收企微推送）用的凭据：只做主动调用时可以为空。
            'token' => $account->token,
            'aes_key' => $account->aesKey,
            'oauth' => ['redirect_url' => $account->redirectUri],
            /*
             * 出站选项交给 EasyWeChat 底下的 Symfony HttpClient。
             * proxy 显式置空：目的地来自我们的配置，不该被进程级 http_proxy 环境变量改写。
             */
            'http' => [
                'base_uri' => self::API_BASE_URI,
                'timeout' => $this->timeout,
                'proxy' => '',
            ],
        ]);

        $application->setLogger($this->logger);
        /*
         * 换掉默认缓存：EasyWeChat 默认用文件缓存，多 worker 下每个进程各持一个 token，
         * 会互相顶掉并表现为随机 401/40014。这里用应用自己那套跨 worker 共享的缓存。
         */
        $application->setCache($this->tokenCache);
        /*
         * 自己指定 token 缓存键：EasyWeChat 的默认键把 secret 写进了缓存键名，
         * 键名会被 Redis 监控与慢查询日志原样打出来。
         * 复用应用自己的 http 客户端与缓存，token 请求与业务请求走同一套超时与代理设置。
         */
        $application->setAccessToken(new WorkAccessToken(
            corpId: $account->corpId,
            secret: $account->secret,
            key: $this->tokens->key($account->alias, $account->corpId),
            cache: $application->getCache(),
            httpClient: $application->getHttpClient(),
        ));

        return $application;
    }
}
