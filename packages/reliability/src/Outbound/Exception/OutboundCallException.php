<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbound\Exception;

use Hyperf\Apex\Outbound\DTO\OutboundRequestDTO;
use Hyperf\Apex\Outbound\OutboundHttpClient;
use RuntimeException;
use Throwable;

/**
 * 出站调用在「拿到响应之前」失败：连不上、超时、TLS 校验不过、地址或凭据没配。
 *
 * 只有这一类才抛异常。**HTTP 4xx / 5xx 不抛**（见 {@see OutboundHttpClient::request()}），
 * 因为「上游明确拒绝」是业务结果：接码平台会用 409 表示号码正被任务占用、
 * 502/503 表示上游平台报错，这些需要连同响应体一起交给业务层判定与留痕。
 */
final class OutboundCallException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $purpose,
        public readonly string $method,
        public readonly string $url,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromTransport(OutboundRequestDTO $request, Throwable $previous): self
    {
        return new self(
            sprintf('调用「%s」失败：%s', $request->purpose, $previous->getMessage()),
            $request->purpose,
            $request->method,
            $request->url,
            $previous,
        );
    }

    /** 配置缺失，请求根本没有发出去 */
    public static function misconfigured(OutboundRequestDTO $request, string $reason): self
    {
        return new self(
            sprintf('调用「%s」失败：%s', $request->purpose, $reason),
            $request->purpose,
            $request->method,
            $request->url,
        );
    }
}
