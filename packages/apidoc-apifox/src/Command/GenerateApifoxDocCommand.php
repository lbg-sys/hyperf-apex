<?php

declare(strict_types=1);

namespace Hyperf\Apex\ApiDoc\Apifox\Command;

use Hyperf\Apex\ApiDoc\Apifox\ApifoxDocumentBuilder;
use Hyperf\Apex\ApiDoc\Support\ApiDocPaths;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Contract\ConfigInterface;
use JsonException;

#[Command(name: 'api-doc:generate-apifox', description: 'Generate the Apifox native document for WebSocket contracts.')]
final class GenerateApifoxDocCommand extends HyperfCommand
{
    public function __construct(
        private readonly ApifoxDocumentBuilder $builder,
        private readonly ConfigInterface $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $json = json_encode(
                $this->builder->build(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            // 序列化失败说明 builder 产出了非法结构，必须报错而不是写出一份半成品文档。
            $this->error('Apifox 文档序列化失败：' . $exception->getMessage());

            return self::FAILURE;
        }

        $directory = $this->outputPath();
        // 同 OpenAPI 生成命令：警告用 @ 抑制，失败由下一行的显式分支报告。
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error('无法创建目录：' . $directory);

            return self::FAILURE;
        }

        $path = ApiDocPaths::outputFile($this->config, ApiDocPaths::APIFOX_FILE);
        file_put_contents($path, $json . PHP_EOL);
        $this->info('Apifox 文档已生成：' . $path);

        return self::SUCCESS;
    }

    /**
     * 产物目录。默认 `BASE_PATH/runtime/api-doc`，与 OpenAPI 生成命令共用 `api_doc.output_path`
     * （解析逻辑统一在 ApiDocPaths，与对外出口也是同一份）。
     */
    private function outputPath(): string
    {
        return ApiDocPaths::outputDirectory($this->config);
    }
}
