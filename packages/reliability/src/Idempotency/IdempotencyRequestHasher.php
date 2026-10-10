<?php

declare(strict_types=1);

namespace Hyperf\Apex\Idempotency;

use Hyperf\Apex\Support\ArrayNormalizer;
use JsonException;
use Psr\Http\Message\ServerRequestInterface;

final class IdempotencyRequestHasher
{
    /** @throws JsonException */
    public function hash(ServerRequestInterface $request): string
    {
        $body = $request->getParsedBody();
        if ($body === null) {
            $body = (string) $request->getBody();
        }

        // 只对 query / body 子项排序，顶层键顺序保持 query、body：
        // 顶层一旦参与排序会改变摘要值，已落库的幂等记录会全部失配。
        $payload = [
            'query' => ArrayNormalizer::sortKeys($request->getQueryParams()),
            'body' => ArrayNormalizer::sortKeys($body),
        ];

        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ));
    }
}
