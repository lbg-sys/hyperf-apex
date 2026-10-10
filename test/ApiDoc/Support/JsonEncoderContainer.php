<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\Support;

use Hyperf\Apex\Http\Response\Encoder\JsonResponseEncoder;
use Psr\Container\ContainerInterface;

/**
 * 只给 JSON 编码器的容器替身。
 *
 * 文档生成只需要一个响应编码器（决定响应的 contentType），不需要真正的容器。
 */
final class JsonEncoderContainer implements ContainerInterface
{
    public function get(string $id): mixed
    {
        return new JsonResponseEncoder();
    }

    public function has(string $id): bool
    {
        return true;
    }
}
