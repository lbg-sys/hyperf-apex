<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\ApiDoc\Apifox\ApifoxDocumentBuilder;
use Hyperf\Apex\ApiDoc\Apifox\Command\GenerateApifoxDocCommand;
use Hyperf\Apex\ApiDoc\Apifox\WsEventMarkdownRenderer;
use Hyperf\Apex\Test\ApiDoc\Support\CommandRunner;
use Hyperf\Apex\Test\Support\TempDirectory;
use Hyperf\Apex\Test\WebSocket\Support\WsAnnotationRegistrar;
use Hyperf\Apex\WebSocket\Attribute\WsGateway;
use Hyperf\Apex\WebSocket\Exception\WsDeclarationException;
use Hyperf\Apex\WebSocket\Registry\WsGatewayScanner;
use Hyperf\Apex\WebSocket\Registry\WsHandlerRegistry;
use Hyperf\Config\Config;
use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
use JsonException;
use PHPUnit\Framework\TestCase;

/**
 * Apifox 导出命令的两条路径：
 * - 生成成功：产物落在 `api_doc.output_path`，顶层只有三个键；
 * - 声明有误（例如一个 `#[WsGateway]` 都没注册）：以声明错误的形式报出来，
 *   而不是写出一份空的 webSocketCollection —— 那会让导入方以为「本来就没有接口」。
 */
final class GenerateApifoxDocCommandTest extends TestCase
{
    private string $outputDir;

    protected function setUp(): void
    {
        $this->outputDir = TempDirectory::create('apex-apifox');
    }

    protected function tearDown(): void
    {
        WsAnnotationRegistrar::clear();
        TempDirectory::remove($this->outputDir);
    }

    public function testSuccessWritesApifoxDocument(): void
    {
        $this->registerGateway();

        [$exit, $text] = CommandRunner::runBare($this->command());

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Apifox 文档已生成', $text);

        $document = $this->document();
        // 顶层只有三个键：不含 projectSetting 与 apiCollection，导入时不可能覆盖现有 HTTP 接口。
        $this->assertSame(['apifoxProject', '$schema', 'webSocketCollection'], array_keys($document));

        $item = $document['webSocketCollection'][0]['items'][0];
        $this->assertSame('/ws/device', $item['api']['path']);
        $this->assertSame(['WebSocket'], $item['api']['tags']);
        $this->assertSame(WsEventMarkdownRenderer::messageTemplate(), $item['api']['requestBody']['message']);
    }

    public function testMissingGatewayDeclarationSurfacesAsDeclarationError(): void
    {
        // 没有注册任何入口：命令必须报错退出，而不是写一份空集合。
        $this->expectException(WsDeclarationException::class);
        $this->expectExceptionMessage('至少需要一个 #[WsGateway] 入口');

        CommandRunner::runBare($this->command());
    }

    private function registerGateway(): void
    {
        WsAnnotationRegistrar::gateway(ApifoxCommandGatewayFixture::class, new WsGateway(
            path: '/ws/device',
            server: 'ws',
            endpoint: 'device',
            name: '设备长连接',
        ));
    }

    private function command(): GenerateApifoxDocCommand
    {
        $config = $this->config();
        $command = new GenerateApifoxDocCommand(
            new ApifoxDocumentBuilder(
                new WsGatewayScanner($config),
                new WsEventMarkdownRenderer(new WsHandlerRegistry(), $config),
                $config,
            ),
            $config,
        );

        // 单测里没有注解扫描，命令名要手工设置。
        $command->setName('api-doc:generate-apifox');

        return $command;
    }

    private function config(): Config
    {
        return new Config([
            'server' => ['servers' => [['name' => 'ws']]],
            'websocket' => ['path' => '/ws', 'excluded' => []],
            'api_doc' => [
                'output_path' => $this->outputDir,
            ],
            'apifox' => ['folder' => 'WebSocket', 'status' => 'developing', 'tags' => ['WebSocket']],
        ]);
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        $contents = file_get_contents($this->outputDir . '/apifox.json');
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

final class ApifoxCommandGatewayFixture implements OnOpenInterface, OnMessageInterface, OnCloseInterface
{
    public function onOpen($server, $request): void
    {
    }

    public function onMessage($server, $frame): void
    {
    }

    public function onClose($server, int $fd, int $reactorId): void
    {
    }
}
