<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Apifox;

use Hyperf\Apex\ApiDoc\Apifox\Command\GenerateApifoxDocCommand;

class ConfigProvider
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => [],
            'commands' => [
                GenerateApifoxDocCommand::class,
            ],
            'listeners' => [],
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'apex apifox 文档导出的配置模板。',
                    'source' => __DIR__ . '/../publish/',
                    'destination' => BASE_PATH . '/config/autoload/',
                ],
            ],
        ];
    }
}
