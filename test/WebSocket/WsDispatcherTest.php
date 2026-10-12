<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Http\ArgumentResolver\RequestDataHydrator;
use Hyperf\Apex\Security\Contract\PrincipalContextInterface;
use Hyperf\Apex\Security\Principal;
use Hyperf\Apex\Test\Support\StubLoggerFactory;
use Hyperf\Apex\Test\WebSocket\Support\WsAnnotationRegistrar;
use Hyperf\Apex\WebSocket\Contract\WsConnectionStoreInterface;
use Hyperf\Apex\WebSocket\DTO\WsSubscriptionResultDTO;
use Hyperf\Apex\WebSocket\Protocol\WsBuiltinEvent;
use Hyperf\Apex\WebSocket\Registry\WsHandlerRegistry;
use Hyperf\Apex\WebSocket\Service\WsDispatcher;
use Hyperf\Apex\WebSocket\Session\WsRequestContext;
use Hyperf\Apex\WebSocket\Session\WsSession;
use Hyperf\Config\Config;
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

    public function testUnknownEventIsRejected(): void
    {
        $response = $this->dispatcher()->dispatch($this->session(), '{"event":"order.paid"}');

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
        $store = new FakeWsConnectionStore();
        $session = $this->session();

        $response = $this->dispatcher($store)->dispatch(
            $session,
            '{"id":"1","event":"sys.subscribe","data":{"room":"room:1"}}',
        );

        $this->assertSame(ErrorCode::SUCCESS->value, $response->code);
        $this->assertSame([['ws:1', 'room:1']], $store->joined);
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

        $response = $this->dispatcher(null, ['max_rooms_per_connection' => 1])->dispatch(
            $session,
            '{"id":"1","event":"sys.subscribe","data":{"room":"second"}}',
        );

        $this->assertSame(ErrorCode::RESOURCE_CONFLICT->value, $response->code);
        $this->assertStringContainsString('最多订阅 1 个房间', $response->message);
        $this->assertSame(['first'], $session->rooms());
    }

    public function testSubscribeReportsRoomCapacityFailure(): void
    {
        $store = new FakeWsConnectionStore();
        $store->joinRoomResult = false;

        $response = $this->dispatcher($store)->dispatch(
            $this->session(),
            '{"id":"1","event":"sys.subscribe","data":{"room":"room:1"}}',
        );

        $this->assertSame(ErrorCode::RESOURCE_CONFLICT->value, $response->code);
        $this->assertStringContainsString('成员已达上限', $response->message);
    }

    public function testUnsubscribeLeavesRoom(): void
    {
        $store = new FakeWsConnectionStore();
        $session = $this->session();
        $session->joinRoom('room:1');

        $response = $this->dispatcher($store)->dispatch(
            $session,
            '{"id":"2","event":"sys.unsubscribe","data":{"room":"room:1"}}',
        );

        $this->assertSame(ErrorCode::SUCCESS->value, $response->code);
        $this->assertSame([['ws:1', 'room:1']], $store->left);
        $this->assertSame([], $session->rooms());
    }

    public function testUnexpectedThrowableBecomesInternalErrorEnvelope(): void
    {
        // 存储层炸了也不能把异常抛给连接层：那会断掉一条本来还健康的连接。
        $store = new FakeWsConnectionStore();
        $store->joinRoomThrowable = new RuntimeException('共享内存表不可用');

        $response = $this->dispatcher($store)->dispatch(
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
        WsAnnotationRegistrar::route(OrderPaidHandler::class, 'paid', 'order.paid');

        $handler = new OrderPaidHandler();
        $context = new FakePrincipalContext();
        $dispatcher = $this->dispatcher(null, [], $context, new MapContainer([OrderPaidHandler::class => $handler]));

        $response = $dispatcher->dispatch(
            WsSession::open('ws', 1, '/ws/hub', $this->principal(), 1_700_000_000),
            '{"id":"1","event":"order.paid","data":{"orderId":"A-1"}}',
        );

        $this->assertSame(ErrorCode::SUCCESS->value, $response->code);
        $this->assertSame('A-1', $handler->received->orderId);
        $this->assertInstanceOf(OrderPaidResultDTO::class, $response->data);
        $this->assertSame('A-1', $response->data->orderId);

        // onMessage 是独立协程：身份投影必须成对出现，否则会串到别的协程。
        $this->assertSame(1, $context->setCalls);
        $this->assertSame(1, $context->clearCalls);
    }

    public function testHandlerWithoutPrincipalIsCalledWithoutProjection(): void
    {
        WsAnnotationRegistrar::route(OrderPaidHandler::class, 'paid', 'order.paid');

        $handler = new OrderPaidHandler();
        $context = new FakePrincipalContext();
        $dispatcher = $this->dispatcher(null, [], $context, new MapContainer([OrderPaidHandler::class => $handler]));

        // 集成凭证接入的会话没有用户身份。
        $dispatcher->dispatch($this->session(), '{"id":"1","event":"order.paid","data":{"orderId":"A-2"}}');

        $this->assertSame('A-2', $handler->received->orderId);
        $this->assertSame(0, $context->setCalls);
        $this->assertSame(0, $context->clearCalls);
    }

    public function testHandlerMayDeclareRequestContext(): void
    {
        WsAnnotationRegistrar::route(OrderTrackHandler::class, 'track', 'order.track');

        $handler = new OrderTrackHandler();
        $dispatcher = $this->dispatcher(null, [], null, new MapContainer([OrderTrackHandler::class => $handler]));

        $response = $dispatcher->dispatch(
            $this->session(),
            '{"id":"2","event":"order.track","data":{"orderId":"A-3"}}',
        );

        $this->assertSame(ErrorCode::SUCCESS->value, $response->code);
        $this->assertSame('ws:1', $handler->connectionId);
        $this->assertSame('A-3', $response->data->orderId);
    }

    public function testInvalidDeclarationBecomesInternalErrorEnvelope(): void
    {
        // 声明写错（把标量当 payload）是部署期错误：注册表直接抛 WsDeclarationException，
        // 派发层把它兜成 INTERNAL_ERROR，而不是让连接层看到异常。
        WsAnnotationRegistrar::route(BrokenDeclarationHandler::class, 'broken', 'order.broken');

        $response = $this->dispatcher()->dispatch($this->session(), '{"id":"4","event":"order.broken"}');

        $this->assertSame(ErrorCode::INTERNAL_ERROR->value, $response->code);
        $this->assertSame('4', $response->id);
    }

    /** @param array<string, int> $config */
    private function dispatcher(
        ?FakeWsConnectionStore $store = null,
        array $config = [],
        ?FakePrincipalContext $context = null,
        ?ContainerInterface $container = null,
    ): WsDispatcher {
        $translator = new Translator(new FileLoader(new Filesystem(), BASE_PATH . '/runtime/lang'), 'zh_CN');

        return new WsDispatcher(
            new WsHandlerRegistry(),
            $store ?? new FakeWsConnectionStore(),
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
            new Config(['websocket' => array_merge(['max_rooms_per_connection' => 20], $config)]),
            StubLoggerFactory::create(),
        );
    }

    private function session(): WsSession
    {
        return WsSession::open('ws', 1, '/ws/hub', null, 1_700_000_000);
    }

    private function principal(): Principal
    {
        return new Principal(userId: 7, userType: 'admin', username: 'tester', sessionId: 'session-id');
    }
}

final class FakeWsConnectionStore implements WsConnectionStoreInterface
{
    public bool $joinRoomResult = true;

    public ?RuntimeException $joinRoomThrowable = null;

    /** @var list<array{0: string, 1: string}> */
    public array $joined = [];

    /** @var list<array{0: string, 1: string}> */
    public array $left = [];

    public function register(WsSession $session): void
    {
    }

    public function unregister(string $connectionId): void
    {
    }

    public function connectionsByUser(string $userType, int $userId): array
    {
        return [];
    }

    public function connectionsByRoom(string $room): array
    {
        return [];
    }

    public function connectionsByEndpoint(string $endpoint): array
    {
        return [];
    }

    public function allConnections(): array
    {
        return [];
    }

    public function countConnections(): int
    {
        return 0;
    }

    public function countUsers(): int
    {
        return 0;
    }

    public function countRooms(): int
    {
        return 0;
    }

    public function joinRoom(string $connectionId, string $room): bool
    {
        if ($this->joinRoomThrowable !== null) {
            throw $this->joinRoomThrowable;
        }

        $this->joined[] = [$connectionId, $room];

        return $this->joinRoomResult;
    }

    public function leaveRoom(string $connectionId, string $room): void
    {
        $this->left[] = [$connectionId, $room];
    }
}

final class FakePrincipalContext implements PrincipalContextInterface
{
    public int $setCalls = 0;

    public int $clearCalls = 0;

    private ?Principal $principal = null;

    public function set(Principal $principal): void
    {
        ++$this->setCalls;
        $this->principal = $principal;
    }

    public function get(): Principal
    {
        return $this->principal ?? throw new RuntimeException('当前协程没有身份。');
    }

    public function clear(): void
    {
        ++$this->clearCalls;
        $this->principal = null;
    }
}

/** 只按类名取实例的容器替身：业务事件派发只需要这一个能力。 */
final class MapContainer implements ContainerInterface
{
    /** @param array<string, object> $map */
    public function __construct(private readonly array $map)
    {
    }

    public function get(string $id): mixed
    {
        return $this->map[$id] ?? throw new RuntimeException(sprintf('容器里没有 %s。', $id));
    }

    public function has(string $id): bool
    {
        return isset($this->map[$id]);
    }
}

final readonly class OrderPaidPayloadDTO extends AbstractDTO
{
    public function __construct(public string $orderId = '')
    {
    }
}

final readonly class OrderPaidResultDTO extends AbstractDTO
{
    public function __construct(public string $orderId = '')
    {
    }
}

final class OrderPaidHandler
{
    public OrderPaidPayloadDTO $received;

    public function paid(OrderPaidPayloadDTO $payload): OrderPaidResultDTO
    {
        $this->received = $payload;

        return new OrderPaidResultDTO($payload->orderId);
    }
}

final class OrderTrackHandler
{
    public string $connectionId = '';

    public function track(WsRequestContext $context, OrderPaidPayloadDTO $payload): OrderPaidResultDTO
    {
        $this->connectionId = $context->connectionId();

        return new OrderPaidResultDTO($payload->orderId);
    }
}

/** 声明错误：把标量当 payload，注册表会在构建定义时拒绝。 */
final class BrokenDeclarationHandler
{
    public function broken(int $id): void
    {
    }
}
