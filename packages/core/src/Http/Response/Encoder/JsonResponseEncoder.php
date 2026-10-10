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
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
