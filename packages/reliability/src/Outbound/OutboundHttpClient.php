<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbound;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Hyperf\Apex\Logging\LoggerChannels;
use Hyperf\Apex\Outbound\DTO\OutboundRequestDTO;
use Hyperf\Apex\Outbound\DTO\OutboundResponseDTO;
use Hyperf\Apex\Outbound\Exception\OutboundCallException;
use Hyperf\Guzzle\ClientFactory;
use Hyperf\Logger\LoggerFactory;
use JsonException;
use Psr\Log\LoggerInterface;

/**
 * 第三方接口的通用出站客户端。
 *
 * 与项目其他出站调用同底座（`Hyperf\Guzzle\ClientFactory`），差异只在「谁来解释响应」：
 * 本层只负责发出去、把响应体解析成结构、把过程写进 outbound 日志频道，
 * **不做成功判定、不做重试** —— 各平台的成功定义与幂等性都不一样，
 * 重试策略必须由知道业务语义的那一层决定，放在这里只会重试一个注定失败的请求。
 *
 * 三条固定行为，避免各调用点各写一遍：
 * 1. `http_errors = false`：4xx / 5xx 作为结果返回；只有传输层失败才抛 {@see OutboundCallException}；
 * 2. `allow_redirects = false` + `proxy = ''`：目的地来自配置，不应被第三方重定向或进程级
 *    `http_proxy` 环境变量改写；
 * 3. 响应体先完整解析 JSON、再按留痕上限截断，避免「截断后的半截 JSON 解析不出来」
 *    把一次正常响应误判成非 JSON。
 */
final class OutboundHttpClient
{
    /** 响应体留痕上限（字符）；只影响日志与 DTO 里的 body，不影响 JSON 解析 */
    private const BODY_LIMIT = 4000;

    /** 日志里附带的响应体摘要上限（字符） */
    private const LOG_BODY_LIMIT = 512;

    private readonly LoggerInterface $logger;

    /** 惰性创建并复用：Guzzle 客户端内部持有连接池，每次请求新建等于放弃 keep-alive。 */
    private ?Client $client = null;

    public function __construct(
        private readonly ClientFactory $clientFactory,
        LoggerFactory $loggerFactory,
    ) {
        $this->logger = LoggerChannels::resolve($loggerFactory, 'outbound', 'outbound');
    }

    /**
     * @throws OutboundCallException 传输层失败或地址缺失；HTTP 错误码不抛，由调用方解释
     */
    public function request(OutboundRequestDTO $request): OutboundResponseDTO
    {
        if (trim($request->url) === '') {
            throw OutboundCallException::misconfigured($request, '请求地址为空，请检查对应的第三方地址配置');
        }

        // 计时统一走单调时钟：墙上时钟被 NTP 校时会回拨，microtime 的差值会出现负数，
        // 而 OutboundRecorder::secondsSince() 本来就是按 hrtime 口径写的。
        $startedAt = hrtime(true);

        try {
            $response = $this->client()->request($request->method, $request->url, $this->options($request));
        } catch (GuzzleException $exception) {
            $this->logger->error(
                sprintf('[出站] %s 传输失败：%s', $request->purpose, $exception->getMessage()),
                $this->context($request) + ['duration_ms' => round(OutboundRecorder::secondsSince($startedAt) * 1000, 2)],
            );

            // 传输层失败（超时、DNS、TLS）：在没有异常分类的前提下统一记失败，
            // 不做「是否超时」的字符串猜测 —— 猜错的结果比不分档更糟。
            OutboundRecorder::record(
                $request->target,
                OutboundRecorder::RESULT_FAILURE,
                OutboundRecorder::secondsSince($startedAt),
            );

            throw OutboundCallException::fromTransport($request, $exception);
        }

        $rawBody = (string) $response->getBody();
        $result = new OutboundResponseDTO(
            purpose: $request->purpose,
            statusCode: $response->getStatusCode(),
            body: mb_substr($rawBody, 0, self::BODY_LIMIT),
            decoded: $this->decode($rawBody),
            durationMs: round(OutboundRecorder::secondsSince($startedAt) * 1000, 2),
        );

        $context = $this->context($request) + [
            'status' => $result->statusCode,
            'duration_ms' => $result->durationMs,
            'response' => $result->summary(self::LOG_BODY_LIMIT),
        ];

        if ($result->isSuccess()) {
            $this->logger->info(sprintf('[出站] %s 成功（HTTP %d）', $request->purpose, $result->statusCode), $context);
        } else {
            $this->logger->warning(
                sprintf('[出站] %s 返回 HTTP %d：%s', $request->purpose, $result->statusCode, $result->errorMessage()),
                $context,
            );
        }

        OutboundRecorder::record(
            $request->target,
            OutboundRecorder::resultFromStatus($result->statusCode),
            OutboundRecorder::secondsSince($startedAt),
        );

        return $result;
    }

    /**
     * 复用同一个 Guzzle 客户端。
     *
     * 每次请求都 create() 会重建 handler 栈与新连接；出站目标通常是固定的几个第三方 host，
     * 复用之后同一 host 的 keep-alive 连接才真正被用上。请求级差异（超时、TLS、代理）
     * 都在 RequestOptions 里逐次传，不依赖客户端构造参数。
     */
    private function client(): Client
    {
        return $this->client ??= $this->clientFactory->create();
    }

    /** @return array<string, mixed> */
    private function options(OutboundRequestDTO $request): array
    {
        $options = [
            'headers' => $request->headers + [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            /* 4xx / 5xx 作为结果返回，不抛异常，交给业务层按状态码与响应体判定 */
            'http_errors' => false,
            /* 禁止跟随跳转：目的地来自第三方配置，不应被重定向带走 */
            'allow_redirects' => false,
            /* 置空代理：避免进程级 http_proxy 环境变量改写请求目的地 */
            'proxy' => '',
            'verify' => $request->verifyTls,
            'timeout' => max(1, $request->timeoutSeconds),
            'connect_timeout' => max(1, $request->connectTimeoutSeconds),
        ];

        if ($request->sendsBody()) {
            $options['body'] = $this->encodeBody($request->json);
        }

        return $options;
    }

    /** @param array<string, mixed> $json */
    private function encodeBody(array $json): string
    {
        /*
         * 空数组必须编成 {} 而不是 []：第三方的请求体 schema 普遍是 object + additionalProperties: false，
         * 发一个 JSON 数组过去会被判成类型错误（400），而调用方想表达的是「不传可选字段」。
         */
        if ($json === []) {
            return '{}';
        }

        return (string) json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return null|array<mixed> 不是合法 JSON 对象/数组时返回 null */
    private function decode(string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @return array<string, mixed> */
    private function context(OutboundRequestDTO $request): array
    {
        return [
            'purpose' => $request->purpose,
            'method' => $request->method,
            'url' => $request->url,
        ];
    }
}
