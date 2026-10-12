<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Http\ArgumentResolver\RequestDataHydrator;
use Hyperf\Apex\Security\Principal;
use Hyperf\Apex\Test\Support\StubLoggerFactory;
use Hyperf\Apex\Test\WebSocket\Support\FakePrincipalContext;
use Hyperf\Apex\Test\WebSocket\Support\FakeWsConnectionStore;
use Hyperf\Apex\Test\WebSocket\Support\FakeWsRoomStore;
use Hyperf\Apex\Test\WebSocket\Support\MapContainer;
use Hyperf\Apex\Test\WebSocket\Support\WsAnnotationRegistrar;
use Hyperf\Apex\Test\WebSocket\Support\WsTestConfig;
use Hyperf\Apex\WebSocket\DTO\WsSubscriptionResultDTO;
use Hyperf\Apex\WebSocket\Protocol\WsBuiltinEvent;
use Hyperf\Apex\WebSocket\RateLimit\WsConnectionRateLimiter;
use Hyperf\Apex\WebSocket\Registry\WsHandlerRegistry;
use Hyperf\Apex\WebSocket\Service\WsDispatcher;
use Hyperf\Apex\WebSocket\Session\WsRequestContext;
use Hyperf\Apex\WebSocket\Session\WsSession;
use Hyperf\Support\Filesystem\Filesystem;
use Hyperf\Translation\FileLoader;
use Hyperf\Translation\Translator;
use Hyperf\Validation\ValidatorFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * 派发链路的行为契约。
 *
 * 唯一的硬约束是「任何错误都只回错误信封，不得把异常抛给连接层」：协议错误、业务拒绝、
 * 未注册事件、声明错误、未预期异常都是如此。下面每个错误用例都断言「拿到了响应」而不是「抛了异常」。
 *
 * 业务事件需要 #[WsRoute] 元数据，单测不启动 server，因此由 registerRoute() 直接写注解收集器的
 * 内部容器；每个用例结束后 clear()，避免串到别的用例。
 */
final class WsDispatcherTest extends TestCase
{
    protected function tearDown(): void
    {
        WsAnnotationRegistrar::clear();
    }

    public function testInvalidJsonReturnsErrorEnvelope(): void
    {
        $response = $this->dispatcher()->dispatch($this->session(), '{');

        $this->assertSame(WsBuiltinEvent::ERROR->value, $response->event);
        $this->assertSame(ErrorCode::INVALID_ARGUMENT->value, $response->code);
        $this->assertSame('消息体不是合法 JSON。', $response->message);
        $this->assertSame('', $response->id);
    }

    public function testScalarPayloadIsRejected(): void
    {
        $response = $this->dispatcher()->dispatch($this->session(), '123');

        $this->assertSame(ErrorCode::INVALID_ARGUMENT->value, $response->code);
        $this->assertSame('消息体必须是 JSON 对象。', $response->message);
    }

    public function testMissingEventIsRejected(): void
    {
        $response = $this->dispatcher()->dispatch($this->session(), '{"id":"9"}');

        $this->assertSame(ErrorCode::INVALID_ARGUMENT->value, $response->code);
        $this->assertSame('消息缺少 event 字段。', $response->message);
        // 能取回 id 就带上：客户端靠它匹配请求与响应。
        $this->assertSame('9', $response->id);
    }

    public function testNonStringIdIsRejected(): void
    {
        $response = $this->dispatcher()->dispatch($this->session(), '{"id":123,"event":"sys.ping"}');

        $this->assertSame(ErrorCode::INVALID_ARGUMENT->value, $response->code);
        $this->assertSame('消息 id 必须是字符串。', $response->message);
    }

    public function testPingBecomesPongWithSameId(): void
    {
        $response = $this->dispatcher()->dispatch($this->session(), '{"id":"7","event":"sys.ping"}');

        $this->assertSame(ErrorCode::SUCCESS->value, $response->code);
        $this->assertSame('7', $response->id);
        $this->assertSame(WsBuiltinEvent::PONG->value, $response->event);
    }

    public function testServerInitiatedBuiltinEventIsRejected(): void
    {
        $response = $this->dispatcher()->dispatch($this->session(), '{"event":"sys.pong"}');

        $this->assertSame(ErrorCode::INVALID_ARGUMENT->value, $response->code);
        $this->assertStringContainsString('由服务端发出', $response->message);
    }

    public function testJsonArrayDataIsRejected(): void
    {
        // JSON 数组与 JSON 对象解码后都是 PHP 数组：形状校验必须在这里做完，
        // 否则会一路走到 payload 水合，报出来的错与真实原因完全不相干。
        $response = $this->dispatcher()->dispatch($this->session(), '{"event":"sys.ping","data":[1,2]}');

        $this->assertSame(ErrorCode::INVALID_ARGUMENT->value, $response->code);
        $this->assertSame('消息 data 必须是 JSON 对象。', $response->message);
    }

    public function testMessageBurstIsRejectedByRateLimit(): void
    {
        // 限流只回错误信封、不断连：重连解决不了刷消息，断连反而会放大重连风暴。
        $dispatcher = $this->dispatcher(null, ['rate_limit' => ['window_seconds' => 3600, 'max_messages' => 1]]);

        $first = $dispatcher->dispatch($this->session(), '{"id":"1","event":"sys.ping"}');
        $this->assertSame(ErrorCode::SUCCESS->value, $first->code);

        $second = $dispatcher->dispatch($this->session(), '{"id":"2","event":"sys.ping"}');
        $this->assertSame(ErrorCode::TOO_MANY_REQUESTS->value, $second->code);
        $this->assertSame(WsBuiltinEvent::ERROR->value, $second->event);
        $this->assertStringContainsString('过于频繁', $second->message);
    }

    public function testRateLimitIsCountedPerConnection(): void
    {
        $dispatcher = $this->dispatcher(null, ['rate_limit' => ['window_seconds' => 3600, 'max_messages' => 1]]);

        $dispatcher->dispatch($this->session(), '{"id":"1","event":"sys.ping"}');

        // 另一条连接有独立的窗口：限流按 connectionId 计数，不能被别人的流量带累。
        $other = WsSession::open('ws', 2, '/ws/hub', null, 1_700_000_000);
        $response = $dispatcher->dispatch($other, '{"id":"1","event":"sys.ping"}');

        $this->assertSame(ErrorCode::SUCCESS->value, $response->code);
    }

    public function testRateLimitCanBeDisabled(): void
    {
        $dispatcher = $this->dispatcher(null, ['rate_limit' => ['window_seconds' => 3600, 'max_messages' => 0]]);

        $this->assertSame(ErrorCode::SUCCESS->value, $dispatcher->dispatch($this->session(), '{"event":"sys.ping"}')->code);
        $this->assertSame(ErrorCode::SUCCESS->value, $dispatcher->dispatch($this->session(), '{"event":"sys.ping"}')->code);
    }

    public function testUnknownEventIsRejected(): void
    {
        $response = $this->dispatcher()->dispatch($this->session(), '{"event":"demo.created"}');

        $this->assertSame(ErrorCode::INVALID_ARGUMENT->value, $response->code);
        $this->assertStringContainsString('未注册的事件', $response->message);
    }

    public function testSubscribeRejectsIllegalRoomName(): void
    {
        $response = $this->dispatcher()->dispatch(
            $this->session(),
            '{"id":"1","event":"sys.subscribe","data":{"room":"有 空格"}}',
        );

        $this->assertSame(ErrorCode::INVALID_ARGUMENT->value, $response->code);
        $this->assertStringContainsString('房间名必须是', $response->message);
    }

    public function testSubscribeJoinsRoomInStoreAndSession(): void
    {
        $roomStore = new FakeWsRoomStore();
        $session = $this->session();

        $response = $this->dispatcher($this->registeredStore(), roomStore: $roomStore)->dispatch(
            $session,
            '{"id":"1","event":"sys.subscribe","data":{"room":"room:1"}}',
        );

        $this->assertSame(ErrorCode::SUCCESS->value, $response->code);
        $this->assertSame([['ws:1', 'room:1']], $roomStore->joined);
        $this->assertSame(['room:1'], $session->rooms());

        $data = $response->data;
        $this->assertInstanceOf(WsSubscriptionResultDTO::class, $data);
        $this->assertSame('room:1', $data->room);
        $this->assertSame(['room:1'], $data->rooms);
    }

    public function testSubscribeRejectsBeyondPerConnectionRoomLimit(): void
    {
        $session = $this->session();
        $session->joinRoom('first');

        $response = $this->dispatcher($this->registeredStore(), ['max_rooms_per_connection' => 1])->dispatch(
            $session,
            '{"id":"1","event":"sys.subscribe","data":{"room":"second"}}',
        );

        $this->assertSame(ErrorCode::RESOURCE_CONFLICT->value, $response->code);
        $this->assertStringContainsString('最多订阅 1 个房间', $response->message);
        $this->assertSame(['first'], $session->rooms());
    }

    public function testSubscribeOnUnregisteredConnectionIsInternalError(): void
    {
        // 房间索引不认识连接索引，所以派发层必须自己保证「连接仍然在索引里」。
        // 连接行已经没了还给房间加成员，等于往成员列表里塞一个 onClose 也摘不掉的 id。
        $roomStore = new FakeWsRoomStore();

        $response = $this->dispatcher(roomStore: $roomStore)->dispatch(
            $this->session(),
            '{"id":"1","event":"sys.subscribe","data":{"room":"room:1"}}',
        );

        $this->assertSame(ErrorCode::INTERNAL_ERROR->value, $response->code);
        $this->assertSame([], $roomStore->joined);
    }

    public function testSubscribeReportsRoomCapacityFailure(): void
    {
        $roomStore = new FakeWsRoomStore();
        $roomStore->joinRoomResult = false;

        $response = $this->dispatcher($this->registeredStore(), roomStore: $roomStore)->dispatch(
            $this->session(),
            '{"id":"1","event":"sys.subscribe","data":{"room":"room:1"}}',
        );

        $this->assertSame(ErrorCode::RESOURCE_CONFLICT->value, $response->code);
        $this->assertStringContainsString('成员已达上限', $response->message);
    }

    public function testUnsubscribeLeavesRoom(): void
    {
        $roomStore = new FakeWsRoomStore();
        $session = $this->session();
        $session->joinRoom('room:1');

        $response = $this->dispatcher(roomStore: $roomStore)->dispatch(
            $session,
            '{"id":"2","event":"sys.unsubscribe","data":{"room":"room:1"}}',
        );

        $this->assertSame(ErrorCode::SUCCESS->value, $response->code);
        $this->assertSame([['ws:1', 'room:1']], $roomStore->left);
        $this->assertSame([], $session->rooms());
    }

    public function testUnexpectedThrowableBecomesInternalErrorEnvelope(): void
    {
        // 存储层炸了也不能把异常抛给连接层：那会断掉一条本来还健康的连接。
        $roomStore = new FakeWsRoomStore();
        $roomStore->joinRoomThrowable = new RuntimeException('房间索引不可用');

        $response = $this->dispatcher($this->registeredStore(), roomStore: $roomStore)->dispatch(
            $this->session(),
            '{"id":"3","event":"sys.subscribe","data":{"room":"room:1"}}',
        );

        $this->assertSame(ErrorCode::INTERNAL_ERROR->value, $response->code);
        $this->assertSame('3', $response->id);
    }

    public function testBuiltinEventDoesNotTouchPrincipalContext(): void
    {
        // 身份投影只发生在业务 handler 调用期，内置事件不该动它。
        $context = new FakePrincipalContext();

        $this->dispatcher(null, [], $context)->dispatch($this->session(), '{"event":"sys.ping"}');

        $this->assertSame(0, $context->setCalls);
        $this->assertSame(0, $context->clearCalls);
    }

    public function testBusinessEventHydratesPayloadAndProjectsPrincipal(): void
    {
        WsAnnotationRegistrar::route(DemoCreatedHandler::class, 'paid', 'demo.created');

        $handler = new DemoCreatedHandler();
        $context = new FakePrincipalContext();
        $dispatcher = $this->dispatcher(null, [], $context, new MapContainer([DemoCreatedHandler::class => $handler]));

        $response = $dispatcher->dispatch(
            WsSession::open('ws', 1, '/ws/hub', $this->principal(), 1_700_000_000),
            '{"id":"1","event":"demo.created","data":{"recordId":"A-1"}}',
        );

        $this->assertSame(ErrorCode::SUCCESS->value, $response->code);
        $this->assertSame('A-1', $handler->received->recordId);
        $this->assertInstanceOf(DemoCreatedResultDTO::class, $response->data);
        $this->assertSame('A-1', $response->data->recordId);

        // onMessage 是独立协程：身份投影必须成对出现，否则会串到别的协程。
        $this->assertSame(1, $context->setCalls);
        $this->assertSame(1, $context->clearCalls);
    }

    public function testHandlerWithoutPrincipalIsCalledWithoutProjection(): void
    {
        WsAnnotationRegistrar::route(DemoCreatedHandler::class, 'paid', 'demo.created');

        $handler = new DemoCreatedHandler();
        $context = new FakePrincipalContext();
        $dispatcher = $this->dispatcher(null, [], $context, new MapContainer([DemoCreatedHandler::class => $handler]));

        // 集成凭证接入的会话没有用户身份。
        $dispatcher->dispatch($this->session(), '{"id":"1","event":"demo.created","data":{"recordId":"A-2"}}');

        $this->assertSame('A-2', $handler->received->recordId);
        $this->assertSame(0, $context->setCalls);
        $this->assertSame(0, $context->clearCalls);
    }

    public function testHandlerMayDeclareRequestContext(): void
    {
        WsAnnotationRegistrar::route(DemoTrackHandler::class, 'track', 'demo.tracked');

        $handler = new DemoTrackHandler();
        $dispatcher = $this->dispatcher(null, [], null, new MapContainer([DemoTrackHandler::class => $handler]));

        $response = $dispatcher->dispatch(
            $this->session(),
            '{"id":"2","event":"demo.tracked","data":{"recordId":"A-3"}}',
        );

        $this->assertSame(ErrorCode::SUCCESS->value, $response->code);
        $this->assertSame('ws:1', $handler->connectionId);
        $this->assertSame('A-3', $response->data->recordId);
    }

    public function testInvalidDeclarationBecomesInternalErrorEnvelope(): void
    {
        // 声明写错（把标量当 payload）是部署期错误：注册表直接抛 WsDeclarationException，
        // 派发层把它兜成 INTERNAL_ERROR，而不是让连接层看到异常。
        WsAnnotationRegistrar::route(BrokenDeclarationHandler::class, 'broken', 'demo.broken');

        $response = $this->dispatcher()->dispatch($this->session(), '{"id":"4","event":"demo.broken"}');

        $this->assertSame(ErrorCode::INTERNAL_ERROR->value, $response->code);
        $this->assertSame('4', $response->id);
    }

    /** @param array<string, mixed> $config */
    private function dispatcher(
        ?FakeWsConnectionStore $store = null,
        array $config = [],
        ?FakePrincipalContext $context = null,
        ?ContainerInterface $container = null,
        ?FakeWsRoomStore $roomStore = null,
    ): WsDispatcher {
        $translator = new Translator(new FileLoader(new Filesystem(), BASE_PATH . '/runtime/lang'), 'zh_CN');
        // 以 ConfigProvider 下发的默认为底，只覆盖与用例相关的项（默认值在包内只有一份）。
        $configObject = WsTestConfig::make(array_replace([
            'max_rooms_per_connection' => 20,
            // 用例里的消息样本只有个位数，窗口给大一点即可保证「同一窗口」这件事是确定的。
            'rate_limit' => ['window_seconds' => 3600, 'max_messages' => 1000],
        ], $config));

        return new WsDispatcher(
            new WsHandlerRegistry(),
            $store ?? new FakeWsConnectionStore(),
            $roomStore ?? new FakeWsRoomStore(),
            new RequestDataHydrator(new ValidatorFactory($translator)),
            $context ?? new FakePrincipalContext(),
            // 默认容器在取处理器时直接失败：没注册路由的用例不该走到这一步。
            $container ?? new class implements ContainerInterface {
                public function get(string $id): mixed
                {
                    throw new RuntimeException('本组用例没有注册业务处理器。');
                }

                public function has(string $id): bool
                {
                    return false;
                }
            },
            $configObject,
            new WsConnectionRateLimiter($configObject),
            StubLoggerFactory::create(),
        );
    }

    private function session(): WsSession
    {
        return WsSession::open('ws', 1, '/ws/hub', null, 1_700_000_000);
    }

    /**
     * 已注册了 ws:1 的连接索引替身。
     *
     * 订阅路径要求「连接仍然在索引里」，而派发层的输入正是一个已经建立好的会话 ——
     * 用例里必须显式表达这个前提，否则测的就不是订阅逻辑而是前提检查。
     */
    private function registeredStore(): FakeWsConnectionStore
    {
        $store = new FakeWsConnectionStore();
        $store->registered['ws:1'] = true;

        return $store;
    }

    private function principal(): Principal
    {
        return new Principal(userId: 7, userType: 'admin', username: 'tester', sessionId: 'session-id');
    }
}

final readonly class DemoCreatedPayloadDTO extends AbstractDTO
{
    public function __construct(public string $recordId = '')
    {
    }
}

final readonly class DemoCreatedResultDTO extends AbstractDTO
{
    public function __construct(public string $recordId = '')
    {
    }
}

final class DemoCreatedHandler
{
    public DemoCreatedPayloadDTO $received;

    public function paid(DemoCreatedPayloadDTO $payload): DemoCreatedResultDTO
    {
        $this->received = $payload;

        return new DemoCreatedResultDTO($payload->recordId);
    }
}

final class DemoTrackHandler
{
    public string $connectionId = '';

    public function track(WsRequestContext $context, DemoCreatedPayloadDTO $payload): DemoCreatedResultDTO
    {
        $this->connectionId = $context->connectionId();

        return new DemoCreatedResultDTO($payload->recordId);
    }
}

/** 声明错误：把标量当 payload，注册表会在构建定义时拒绝。 */
final class BrokenDeclarationHandler
{
    public function broken(int $id): void
    {
    }
}
