<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\ApiDoc\Command\GenerateApiDocCommand;
use Hyperf\Apex\Test\ApiDoc\Support\CommandRunner;
use Hyperf\Apex\Test\ApiDoc\Support\OpenApiGeneratorHarness;
use Hyperf\Apex\Test\Support\TempDirectory;
use Hyperf\Config\Config;
use JsonException;
use PHPUnit\Framework\TestCase;

/**
 * 生成命令的成败判据：**规格校验里的任何 error 或 warning 都让命令失败，且一个字节都不写**。
 *
 * 半成品文档比没有文档更危险 —— 下游导入之后没人知道它缺了什么。用例同时钉住提示里要带
 * 级别与原文（只说「校验失败」等于让人去猜是哪一条），以及「目录建不出来」时也要以失败退出。
 */
final class GenerateApiDocCommandTest extends TestCase
{
    private string $outputDir;

    protected function setUp(): void
    {
        $this->outputDir = TempDirectory::create('apex-apidoc');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->outputDir);
    }

    public function testValidationFailureReturnsFailureAndWritesNothing(): void
    {
        // 夹具里声明了「数组但没有元素类型」，规格校验会把它归到 warning。
        [$exit, $text] = CommandRunner::runBare($this->command(['app_path' => __DIR__ . '/EndpointInvalid']));

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('[warning]', $text);
        $this->assertStringContainsString('has type "array" but no items', $text);
        $this->assertFileDoesNotExist($this->outputDir . '/openapi.json');
    }

    public function testSuccessWritesDocumentToConfiguredDirectory(): void
    {
        [$exit, $text] = CommandRunner::runBare($this->command(['app_path' => __DIR__ . '/Endpoint']));

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('已生成', $text);
        $this->assertFileExists($this->outputDir . '/openapi.json');

        $document = $this->document();
        $this->assertSame('3.1.1', $document['openapi']);
        $this->assertArrayHasKey('/fixture/get_list', $document['paths']);
        $this->assertArrayHasKey('components', $document);
    }

    public function testOutputDirectoryIsCreatedWhenMissing(): void
    {
        // 传一个还不存在的多级目录：命令应当自己建出来，而不是直接失败。
        $nested = $this->outputDir . '/nested/deep';

        [$exit] = CommandRunner::runBare(
            $this->command(['app_path' => __DIR__ . '/Endpoint', 'output_path' => $nested])
        );

        $this->assertSame(0, $exit);
        $this->assertFileExists($nested . '/openapi.json');
    }

    public function testUncreatableOutputDirectoryReturnsFailure(): void
    {
        // 父路径是个文件，mkdir 必然失败：必须报错退出，而不是静默什么都不写。
        $blocker = tempnam(sys_get_temp_dir(), 'apex-blocker');
        $this->assertIsString($blocker);

        try {
            [$exit, $text] = CommandRunner::runBare($this->command([
                'app_path' => __DIR__ . '/Endpoint',
                'output_path' => $blocker . '/sub',
            ]));

            $this->assertSame(1, $exit);
            $this->assertStringContainsString('无法创建目录', $text);
        } finally {
            @unlink($blocker);
        }
    }

    /** @param array<string, mixed> $apiDoc */
    private function command(array $apiDoc): GenerateApiDocCommand
    {
        $apiDoc = array_merge(['output_path' => $this->outputDir], $apiDoc);

        $command = new GenerateApiDocCommand(
            OpenApiGeneratorHarness::factory($apiDoc),
            new Config(['api_doc' => $apiDoc]),
        );

        // 单测里没有注解扫描，命令名要手工设置。
        $command->setName('api-doc:generate');

        return $command;
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        $contents = file_get_contents($this->outputDir . '/openapi.json');
        $this->assertIsString($contents);

        try {
            $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->fail('产物不是合法 JSON：' . $exception->getMessage());
        }

        $this->assertIsArray($document);

        return $document;
    }
}
