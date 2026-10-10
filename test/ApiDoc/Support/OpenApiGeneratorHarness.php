<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\Support;

use Hyperf\Apex\ApiDoc\OpenApiGenerator;
use Hyperf\Apex\ApiDoc\OpenApiGeneratorFactory;
use Hyperf\Apex\Http\Response\ApiResponseDTO;
use Hyperf\Apex\Http\Response\ApiResponseFactory;
use Hyperf\Apex\Http\Response\ResponseEncoderResolver;
use Hyperf\Config\Config;

/**
 * 按「生成命令同一条配线」装配生成器，供多个用例文件共用。
 *
 * 默认值与 `publish/api_doc.php` 的默认值保持一致（除了 servers 给了个样例）：
 * 用例的断言都建立在这份配置上，散在各文件里就会各自漂移。
 */
final class OpenApiGeneratorHarness
{
    /** @var array<string, mixed> */
    private const DEFAULTS = [
        'version' => '3.1.1',
        'info' => ['title' => '示例接口', 'version' => '1.0.0'],
        'servers' => [['url' => 'https://api.example.com', 'description' => '生产']],
        'excluded' => [],
    ];

    /**
     * @param array<string, mixed> $apiDoc
     */
    public static function factory(array $apiDoc = []): OpenApiGeneratorFactory
    {
        $responses = new ApiResponseFactory(new Config(['response' => ['response_dto' => ApiResponseDTO::class]]));

        return new OpenApiGeneratorFactory(
            $responses,
            new ResponseEncoderResolver(new JsonEncoderContainer(), $responses),
            new Config(['api_doc' => array_merge(self::DEFAULTS, $apiDoc)]),
        );
    }

    /**
     * @param array<string, mixed> $apiDoc
     */
    public static function generator(array $apiDoc = []): OpenApiGenerator
    {
        return self::factory($apiDoc)->make();
    }
}
