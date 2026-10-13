<?php

declare(strict_types=1);

namespace Hyperf\Apex\WeCom\Api;

use EasyWeChat\Kernel\HttpClient\AccessTokenAwareClient;
use Hyperf\Apex\WeCom\Cache\AccessTokenStore;
use Hyperf\Apex\WeCom\DTO\WeComAccountDTO;
use Hyperf\Apex\WeCom\Exception\WeComException;
use Hyperf\Apex\WeCom\Manager\WeComManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 一个企业的企微服务端接口出口：发出去、解释响应、把失败归一成业务异常。
 *
 * 三件各调用点否则要各写一遍的事：
 * 1. **access_token 自愈**：拿到 40014 / 42001 就丢掉缓存重发一次。token 被同企业的其它应用
 *    顶掉是常态，不重取会让一次偶发变成用户可见的失败；
 * 2. **错误归一**：企微的失败信息在响应体 `errcode` 里，HTTP 状态一律 200，
 *    因此「HTTP 成功」不代表调用成功，必须读响应体判定；
 * 3. **凭据不进日志**：只记路径、errcode、errmsg，不记 query / body —— 里面是 code、userid 一类的标识。
 *
 * 高级用法（上传媒体、回调验签）请直接用 {@see WeComManager::application()}
 * 拿到的 EasyWeChat 应用实例，本类只覆盖「发一个 GET/POST 并看 errcode」这条主干。
 */
final class WeComApiClient
{
    public function __construct(
        private readonly AccessTokenAwareClient $client,
        private readonly AccessTokenStore $tokens,
        private readonly WeComAccountDTO $account,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     *
     * @throws WeComException
     */
    public function get(string $path, array $query = []): array
    {
        return $this->call('GET', $path, ['query' => $query]);
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     *
     * @throws WeComException
     */
    public function post(string $path, array $json = []): array
    {
        return $this->call('POST', $path, ['json' => $json]);
    }

    /**
     * 传原始 Symfony HTTP 选项的出口（例如带 `body` 上传二进制以外的场景）。
     *
     * 选项必须能重复构造：本类在 token 失效时会原样重发一次，
     * 传进来的 resource / 一次性流会在第二次发送时已经读到末尾。
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     *
     * @throws WeComException
     */
    public function request(string $method, string $path, array $options = []): array
    {
        return $this->call(strtoupper($method), $path, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     *
     * @throws WeComException
     */
    private function call(string $method, string $path, array $options, bool $tokenRefreshed = false): array
    {
        $path = ltrim($path, '/');

        try {
            /* toArray(false)：HTTP 4xx / 5xx 也按结果处理 —— 传输层的异常才抛，
             * 状态码与 errcode 一起进日志，由本层统一判定。 */
            /** @var array<string, mixed> $data */
            $data = $this->client->request($method, $path, $options)->toArray(false);
        } catch (Throwable $exception) {
            $this->logger->error('企业微信接口调用失败。', [
                'api' => $path,
                'alias' => $this->account->alias,
                'method' => $method,
                'exception' => $exception,
            ]);

            throw WeComException::apiUnavailable(0, $exception);
        }

        $errcode = (int) ($data['errcode'] ?? 0);
        if ($errcode === 0) {
            return $data;
        }

        if (! $tokenRefreshed && WeComException::isAccessTokenInvalid($errcode)) {
            // 重发前必须清缓存：AccessTokenAwareClient 每次请求都会从缓存里取 token 拼进 query，
            // 不清就等于原样重发一个注定失败的请求。
            $this->tokens->forget($this->account->alias, $this->account->corpId);

            return $this->call($method, $path, $options, true);
        }

        $this->logger->error('企业微信接口返回错误。', [
            'api' => $path,
            'alias' => $this->account->alias,
            'method' => $method,
            'errcode' => $errcode,
            'errmsg' => (string) ($data['errmsg'] ?? ''),
        ]);

        throw WeComException::apiUnavailable($errcode);
    }
}
