<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\ApiDoc\Apifox\WsEventMarkdownRenderer;
use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\DTO\Attribute\ArrayOf;
use Hyperf\Apex\DTO\Attribute\Deprecated;
use Hyperf\Apex\DTO\Attribute\EnumValue;
use Hyperf\Apex\DTO\Attribute\FieldDescription;
use Hyperf\Apex\DTO\Attribute\Length;
use Hyperf\Apex\DTO\Attribute\MapOf;
use Hyperf\Apex\DTO\Attribute\Minimum;
use Hyperf\Apex\DTO\Attribute\Required;
use Hyperf\Apex\Enum\Interface\EnumInterface;
use Hyperf\Apex\Enum\Traits\EnumTrait;
use Hyperf\Apex\Test\WebSocket\Support\WsAnnotationRegistrar;
use Hyperf\Apex\WebSocket\Attribute\WsGateway;
use Hyperf\Apex\WebSocket\Definition\WsGatewayDefinition;
use Hyperf\Apex\WebSocket\Registry\WsGatewayScanner;
use Hyperf\Apex\WebSocket\Registry\WsHandlerRegistry;
use Hyperf\Config\Config;
use Hyperf\Constants\Annotation\Constants;
use Hyperf\Constants\Annotation\Message;
use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
use Hyperf\Server\ServerInterface;
use PHPUnit\Framework\TestCase;

/**
 * WebSocket 契约 Markdown 的渲染契约。
 *
 * 字段表是这套文档里唯一的结构化部分：类型列要能区分数组、映射与枚举对象，
 * 说明列要把约束（长度、取值范围、默认值、已废弃）与 `#[EnumValue]` 追加的可选值
 * 拼在一格里 —— 表格不能有换行，所以换行必须换成 `<br>`。
 *
 * 请求示例要跟类型列一致：枚举是对象、映射是对象、数组是列表，三种不能都糊成 `[]`。
 */
final class WsEventMarkdownRendererTest extends TestCase
{
    protected function tearDown(): void
    {
        WsAnnotationRegistrar::clear();
    }

    public function testFieldTableCoversArrayMapEnumNullableAndConstraints(): void
    {
        $description = $this->description();

        $this->assertStringContainsString('| `hubNo` | string | 是 | 设备编号；长度 1-64 |', $description);
        $this->assertStringContainsString('| `items` | array<RendererItemDTO> | 否 | 上报项 |', $description);
        $this->assertStringContainsString('| `extra` | map<string, string> | 否 | 扩展字段 |', $description);
        // 可空标记拼在类型名之后。
        $this->assertStringContainsString('| `note` | string|null | 否 | 备注 |', $description);
        // 取值范围、默认值、已废弃依次拼接。
        $this->assertStringContainsString('| `count` | integer | 否 | 次数；取值范围 1-不限；默认值 0；已废弃 |', $description);
    }

    public function testEnumFieldShowsObjectTypeAndBreaksTheMultiLineDescription(): void
    {
        $description = $this->description();

        // 响应侧的枚举经 EnumTrait 序列化成 {key, name}，不是标量。
        $this->assertStringContainsString('| `status` | `{key, name}` | 否 |', $description);
        // #[EnumValue] 追加的可选值说明本身带换行，进表格必须换成 <br>，否则整张表错位。
        $this->assertStringContainsString('状态<br>可选值：enabled=启用；disabled=停用', $description);
        $this->assertStringNotContainsString("状态\n可选值", $description);
    }

    public function testResponseTableIsRenderedForTheDeclaredResponseDto(): void
    {
        $description = $this->description();

        $this->assertStringContainsString('**响应**（`RendererResultDTO`）', $description);
        $this->assertStringContainsString('| `ok` | boolean | 否 | 是否成功；默认值 false |', $description);
    }

    public function testVoidResponseSaysSoInsteadOfLeavingAnEmptyTable(): void
    {
        WsAnnotationRegistrar::route(VoidResponseHandler::class, 'handle', 'hub.ack');

        $this->assertStringContainsString('**响应**：无响应体（`data` 为空）。', $this->description());
    }

    public function testRequestSampleMatchesTheDeclaredTypes(): void
    {
        $description = $this->description();

        // 数组是列表、映射与枚举是对象、标量按类型给占位值 ——
        // 三种都给 [] 就等于示例与同一行的类型列互相矛盾。
        $this->assertStringContainsString(
            '"data":{"hubNo":"","items":[],"extra":{},"status":{"key":"value","name":"说明"},"note":"","count":0}',
            $description,
        );
    }

    public function testBuiltinEventTableListsDirectionOfEveryEvent(): void
    {
        $description = $this->description();

        // 方向列由 isClientInitiated() 决定：客户端不得调用服务端事件，反之亦然。
        $this->assertStringContainsString('| `sys.ping` | 客户端 → 服务端 | 心跳请求 |', $description);
        $this->assertStringContainsString('| `sys.pong` | 服务端 → 客户端 | 心跳应答 |', $description);
    }

    public function testExcludedHandlerIsKeptOutOfTheEventSection(): void
    {
        // 事件与入口无关，所以入口被排除不代表事件也被排除 —— 渲染时必须再按同一份清单过滤一次。
        WsAnnotationRegistrar::route(ProbeOnlyHandler::class, 'handle', 'hub.probe');

        $description = $this->description(['websocket' => ['path' => '/ws', 'excluded' => ['*ProbeOnlyHandler']]]);

        $this->assertStringContainsString('### `hub.report`', $description);
        $this->assertStringNotContainsString('hub.probe', $description);
    }

    /** @param array<string, mixed> $config */
    private function description(array $config = []): string
    {
        WsAnnotationRegistrar::gateway(RendererGatewayFixture::class, new WsGateway(
            path: '/ws/hub',
            server: 'ws',
            endpoint: 'hub',
            name: '主连接',
        ));

        $config = new Config(array_merge([
            // 入口声明的 server 必须是 WebSocket 服务：握手回调只挂在那种端口上。
            'server' => ['servers' => [['name' => 'ws', 'type' => ServerInterface::SERVER_WEBSOCKET]]],
            'websocket' => ['path' => '/ws', 'excluded' => []],
        ], $config));

        // 主事件始终注册：排除清单按类名匹配，只影响被排除的那个 handler。
        WsAnnotationRegistrar::route(RendererPayloadHandler::class, 'handle', 'hub.report');

        $definition = (new WsGatewayScanner($config))->all()[RendererGatewayFixture::class];
        $this->assertInstanceOf(WsGatewayDefinition::class, $definition);

        return (new WsEventMarkdownRenderer(new WsHandlerRegistry(), $config))->render($definition);
    }
}

#[Constants]
enum RendererStatusEnum: string implements EnumInterface
{
    use EnumTrait;

    #[Message('启用')]
    case ENABLED = 'enabled';

    #[Message('停用')]
    case DISABLED = 'disabled';
}

final readonly class RendererItemDTO extends AbstractDTO
{
    public function __construct(
        #[FieldDescription('条目名')]
        public string $label = '',
    ) {
    }
}

/**
 * 覆盖字段表的各类形态：必填、长度、数组、映射、枚举、可空、取值范围与已废弃。
 */
final readonly class RendererPayloadDTO extends AbstractDTO
{
    public function __construct(
        #[Required]
        #[FieldDescription('设备编号')]
        #[Length(min: 1, max: 64)]
        public string $hubNo,
        #[FieldDescription('上报项')]
        #[ArrayOf(RendererItemDTO::class)]
        public array $items = [],
        #[FieldDescription('扩展字段')]
        #[MapOf('string')]
        public array $extra = [],
        #[FieldDescription('状态')]
        #[EnumValue(RendererStatusEnum::class)]
        public string $status = '',
        #[FieldDescription('备注')]
        public ?string $note = null,
        #[FieldDescription('次数')]
        #[Minimum(1)]
        #[Deprecated]
        public int $count = 0,
    ) {
    }
}

final readonly class RendererResultDTO extends AbstractDTO
{
    public function __construct(
        #[FieldDescription('是否成功')]
        public bool $ok = false,
    ) {
    }
}

final class RendererGatewayFixture implements OnOpenInterface, OnMessageInterface, OnCloseInterface
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

final class RendererPayloadHandler
{
    public function handle(RendererPayloadDTO $payload): RendererResultDTO
    {
        return new RendererResultDTO();
    }
}

final class VoidResponseHandler
{
    public function handle(RendererPayloadDTO $payload): void
    {
    }
}

final class ProbeOnlyHandler
{
    public function handle(RendererPayloadDTO $payload): void
    {
    }
}
