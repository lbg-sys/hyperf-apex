<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\Response\Encoder;

use Hyperf\Apex\Http\Response\Contract\ApiResponseInterface;
use Hyperf\Apex\Http\Response\Contract\ResponseEncoderInterface;

final class JsonResponseEncoder implements ResponseEncoderInterface
{
    public function contentType(): string
    {
        return 'application/json';
    }

    public function encode(ApiResponseInterface $response): string
    {
        return json_encode(
            $response->toArray(),
            // JSON_INVALID_UTF8_SUBSTITUTE：库里可能存着非 UTF-8 的字节（历史数据、从上游
            // 原样回放的响应体），严格模式下一条脏数据会把整条成功响应升级成 500 ——
            // 用替补字符替换掉那一段，其余内容照常返回。其它 JSON 错误仍然抛。
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
