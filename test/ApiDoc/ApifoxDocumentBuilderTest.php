<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\ApiDoc\Apifox\ApifoxDocumentBuilder;
use Hyperf\Apex\ApiDoc\Apifox\WsEventMarkdownRenderer;
use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\DTO\Attribute\FieldDescription;
use Hyperf\Apex\Test\WebSocket\Support\WsAnnotationRegistrar;
use Hyperf\Apex\WebSocket\Attribute\WsGateway;
use Hyperf\Apex\WebSocket\Registry\WsGatewayScanner;
use Hyperf\Apex\WebSocket\Registry\WsHandlerRegistry;
use Hyperf\Config\Config;
use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
use PHPUnit\Framework\TestCase;

/**
 * Apifox 原生格式的组装契约。
 *
 * 顶层只有三个键这条最要紧：不含 projectSetting / moduleSettings / apiCollection，
 * 因此导入时不存在「导错项目」与「清空现有 HTTP 接口」的风险 —— 多一个键就多一份这类风险。
 *
 * WebSocket 接口在 Apifox 里没有数据结构的位置，契约只能渲染进 description，
 * 所以这里也顺带验证「字段说明、内置事件、业务事件」确实进了那段 Markdown。
 */
final class ApifoxDocumentBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        WsAnnotationRegistrar::clear();
    }

    public function testDocumentHasExactlyThreeTopLevelKeys(): void
    {
        $this->registerGateway();

        $document = $this->builder()->build();

        $this->assertSame(['apifoxProject', '$schema', 'webSocketCollection'], array_keys($document));
        $this->assertSame('1.0.0', $document['apifoxProject']);
        $this->assertSame(['app' => 'apifox', 'type' => 'project', 'version' => '1.2.0'], $document['$schema']);
    }

    public function testGatewayBecomesAnItemWithStableId(): void
    {
        $this->registerGateway();

        $collection = $this->builder()->build()['webSocketCollection'][0];
        $this->assertSame('WebSocket', $collection['name']);

        $item = $collection['items'][0];
        $this->assertSame('主连接', $item['name']);
        $this->assertSame('/ws/hub', $item['api']['path']);
        $this->assertSame(WsEventMarkdownRenderer::messageTemplate(), $item['api']['requestBody']['message']);
        $this->assertSame('json', $item['api']['requestBody']['messageType']);
        $this->assertSame('developing', $item['api']['status']);
        $this->assertSame(['WebSocket'], $item['api']['tags']);
        // 握手参数不生成：token 每个环境不同，在 Apifox 环境变量里配一次更合理。
        $this->assertSame(['query' => [], 'path' => [], 'cookie' => [], 'header' => []], $item['api']['parameters']);

        // 稳定 id：重复导入必须匹配到同一条接口，否则每导一次就多一条。
        $this->assertMatchesRegularExpression('/^ws-[0-9a-f]{32}$/', $item['api']['id']);
        $this->assertSame($item['api']['id'], $this->builder()->build()['webSocketCollection'][0]['items'][0]['api']['id']);
    }

    public function testDescriptionCarriesHeaderEnvelopeAndBuiltinEvents(): void
    {
        $this->registerGateway();

        $description = $this->description();

        $this->assertStringContainsString('## 主连接', $description);
        $this->assertStringContainsString('连接路径：`/ws/hub`', $description);
        $this->assertStringContainsString('接入端标识：`hub`', $description);
        $this->assertStringContainsString('## 统一信封', $description);
        $this->assertStringContainsString('## 内置事件', $description);
        // 没有注册业务事件时给出明确说明，而不是留一段空白让人以为文档坏了。
        $this->assertStringContainsString('当前没有注册任何业务事件', $description);
    }

    public function testBusinessEventSectionRendersFieldTable(): void
    {
        $this->registerGateway();
        WsAnnotationRegistrar::route(HubStatusHandler::class, 'handle', 'hub.status');

        $description = $this->description();

        $this->assertStringContainsString('### `hub.status`', $description);
        $this->assertStringContainsString(HubStatusHandler::class . '::handle', $description);
        // 字段表复用 DTOSchemaFactory：#[FieldDescription] 自动进文档，不需要维护第二份说明。
        // 有默认值的字段把缺省值也拼进说明列（`hubNo = ''` → 默认值 ""）。
        $this->assertStringContainsString('| `hubNo` | string | 否 | 设备编号；默认值 "" |', $description);
        // 错误码表按 WS 侧的真实语义列出（事件未注册、payload 校验失败、房间上限…）。
        $this->assertStringContainsString('## 错误码', $description);
        $this->assertStringContainsString('事件未注册', $description);
    }

    public function testExcludedGatewayKeepsOutOfTheCollection(): void
    {
        $this->registerGateway();

        // 测试入口等不对外：只从文档里去掉，路由照旧注册，否则连不上就没法手工验证。
        $document = $this->builder([
            'websocket' => ['path' => '/ws', 'excluded' => ['*ApifoxGatewayFixture']],
        ])->build();

        $this->assertSame([], $document['webSocketCollection'][0]['items']);
    }

    public function testFolderTagsAndStatusFallBackToDefaults(): void
    {
        $this->registerGateway();

        // 配置里留空/写错类型时回落到默认值，而不是产出空字符串。
        $document = $this->builder([
            'apifox' => ['folder' => '', 'status' => 123, 'tags' => ['标签', 42, '']],
        ])->build();

        $this->assertSame('WebSocket', $document['webSocketCollection'][0]['name']);
        $this->assertSame(['标签'], $document['webSocketCollection'][0]['items'][0]['api']['tags']);
        $this->assertSame('developing', $document['webSocketCollection'][0]['items'][0]['api']['status']);
    }

    private function description(): string
    {
        $description = $this->builder()->build()['webSocketCollection'][0]['items'][0]['api']['description'] ?? null;
        $this->assertIsString($description, '入口的契约应当渲染进 description。');

        return $description;
    }

    private function registerGateway(): void
    {
        WsAnnotationRegistrar::gateway(ApifoxGatewayFixture::class, new WsGateway(
            path: '/ws/hub',
            server: 'ws',
            endpoint: 'hub',
            name: '主连接',
        ));
    }

    /** @param array<string, mixed> $config */
    private function builder(array $config = []): ApifoxDocumentBuilder
    {
        $config = new Config(array_merge([
            'server' => ['servers' => [['name' => 'ws']]],
            'websocket' => ['path' => '/ws', 'excluded' => []],
            'apifox' => ['folder' => 'WebSocket', 'status' => 'developing', 'tags' => ['WebSocket']],
        ], $config));

        return new ApifoxDocumentBuilder(
            new WsGatewayScanner($config),
            new WsEventMarkdownRenderer(new WsHandlerRegistry(), $config),
            $config,
        );
    }
}

final class ApifoxGatewayFixture implements OnOpenInterface, OnMessageInterface, OnCloseInterface
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

final readonly class HubStatusPayloadDTO extends AbstractDTO
{
    public function __construct(
        #[FieldDescription('设备编号')]
        public string $hubNo = '',
    ) {
    }
}

/** 处理器说明落进 Markdown 的「处理器」一行，这里顺带验证类名渲染。 */
final class HubStatusHandler
{
    public function handle(HubStatusPayloadDTO $payload): void
    {
    }
}
