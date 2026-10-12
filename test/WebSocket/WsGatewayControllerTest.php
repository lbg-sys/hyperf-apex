<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket;

use Hyperf\Apex\Http\ArgumentResolver\RequestDataHydrator;
use Hyperf\Apex\Security\Principal;
use Hyperf\Apex\Test\Support\StubLoggerFactory;
use Hyperf\Apex\Test\WebSocket\Support\FakePrincipalContext;
use Hyperf\Apex\Test\WebSocket\Support\FakeSender;
use Hyperf\Apex\Test\WebSocket\Support\FakeWsConnectionStore;
use Hyperf\Apex\Test\WebSocket\Support\FakeWsRoomStore;
use Hyperf\Apex\Test\WebSocket\Support\MapContainer;
use Hyperf\Apex\Test\WebSocket\Support\WsTestConfig;
use Hyperf\Apex\WebSocket\Attribute\WsGateway;
use Hyperf\Apex\WebSocket\Auth\WsHandshakeContext;
use Hyperf\Apex\WebSocket\Controller\WsGatewayController;
use Hyperf\Apex\WebSocket\RateLimit\WsConnectionRateLimiter;
use Hyperf\Apex\WebSocket\Registry\WsGatewayScanner;
use Hyperf\Apex\WebSocket\Registry\WsHandlerRegistry;
use Hyperf\Apex\WebSocket\Service\WsDispatcher;
use Hyperf\Apex\WebSocket\Session\WsSession;
use Hyperf\Context\Context as CoContext;
use Hyperf\Support\Filesystem\Filesystem;
use Hyperf\Translation\FileLoader;
use Hyperf\Translation\Translator;
use Hyperf\Validation\ValidatorFactory;
use Hyperf\WebSocketServer\Context as ConnectionContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * 连接生命周期：这是整个网关唯一的落点。
 *
 * onOpen 建会话 + 写索引（注册不成功就不能假装连接已建立）、onMessage 交给派发器并把
 * 每条消息都算作活跃、onClose 按会话记录的房间精确退订并清干净三处状态。这些分支
 * 出了错都表现为「连接静默失联」或「推送发给了已经关闭的 fd」，所以逐条钉住。
 */
final class WsGatewayControllerTest extends TestCase
{
    private const FD = 8001;

    protected function tearDown(): void
    {
        WsSession::clear(self::FD);
        WsHandshakeContext::clear();
        CoContext::set(ConnectionContext::FD, 0);
    }

    public function testOnOpenRegistersTheConnectionAndStoresTheSession(): void
    {
        $store = new FakeWsConnectionStore();
        $sender = new FakeSender();

        $this->open($this->controller(store: $store, sender: $sender), self::FD);

        $this->assertSame(['ws:' . self::FD => true], $store->registered);

        $session = WsSession::find(self::FD);
        $this->assertNotNull($session);
        $this->assertSame('ws', $session->server);
        // endpoint 是推送寻址用的接入端标识，不是握手路径。
        $this->assertSame('hub', $session->endpoint);
        $this->assertNull($session->principal);

        $this->assertSame([], $sender->disconnected);
        $this->assertSame([], $sender->pushed);
    }

    public function testOnOpenMovesTheHandshakePrincipalIntoTheSession(): void
    {
        $controller = $this->controller();
        CoContext::set(ConnectionContext::FD, self::FD);
        WsHandshakeContext::setPrincipal(new Principal(userId: 7, userType: 'hub', username: 'u', sessionId: 's-7'));

        $this->open($controller, self::FD);

        $this->assertSame(7, WsSession::find(self::FD)?->principal?->userId);
        // 中转值只服务于「握手中间件 → onOpen」这一跳，组装完会话就该消失。
        $this->assertNull(WsHandshakeContext::principal());
    }

    public function testOnOpenDisconnectsWhenRegistrationFails(): void
    {
        // 索引写不进去（表满、超单用户上限）时连接不能假装建立：注册成功才代表推送找得到它。
        $store = new FakeWsConnectionStore();
        $store->registerThrowable = new RuntimeException('表容量已满');
        $sender = new FakeSender();

        $this->open($this->controller(store: $store, sender: $sender), self::FD);

        $this->assertSame([self::FD], $sender->disconnected);
        $this->assertNull(WsSession::find(self::FD));
    }

    public function testOnOpenWithoutResolvableFdCreatesNothing(): void
    {
        $store = new FakeWsConnectionStore();
        CoContext::set(ConnectionContext::FD, 0);

        $this->controller(store: $store)->onOpen(new stdClass(), new stdClass());

        $this->assertSame([], $store->registered);
        $this->assertNull(WsSession::find(0));
    }

    public function testOnMessageWithoutASessionDisconnectsInsteadOfGuessingIdentity(): void
    {
        $sender = new FakeSender();

        $this->controller(sender: $sender)->onMessage(new stdClass(), $this->frame(self::FD, '{"id":"1","event":"sys.ping"}'));

        $this->assertSame([self::FD], $sender->disconnected);
        $this->assertSame([], $sender->pushed);
    }

    public function testOnMessageRefreshesActivityAndAnswersPing(): void
    {
        $store = new FakeWsConnectionStore();
        $sender = new FakeSender();
        $controller = $this->controller(store: $store, sender: $sender);
        $this->open($controller, self::FD);

        $controller->onMessage(new stdClass(), $this->frame(self::FD, '{"id":"1","event":"sys.ping"}'));

        // 收到消息即算活跃：空闲回收据此判断连接是不是还活着。
        $this->assertCount(1, $store->touched);
        $this->assertSame('ws:' . self::FD, $store->touched[0][0]);
        $this->assertGreaterThan(0, $store->touched[0][1]);

        $this->assertCount(1, $sender->pushed);
        $this->assertSame(self::FD, $sender->pushed[0][0]);
        $this->assertStringContainsString('"event":"sys.pong"', $sender->pushed[0][1]);
        $this->assertStringContainsString('"id":"1"', $sender->pushed[0][1]);
    }

    public function testOnMessageRejectsBinaryFramesWithoutParsingThem(): void
    {
        $sender = new FakeSender();
        $controller = $this->controller(sender: $sender);
        $this->open($controller, self::FD);

        $controller->onMessage(new stdClass(), $this->frame(self::FD, 'binary', WEBSOCKET_OPCODE_BINARY));

        $this->assertStringContainsString('只接受文本帧', $sender->pushed[0][1]);
    }

    public function testOnMessageRejectsFramesOverTheConfiguredLimit(): void
    {
        // 超限帧回错误信封而不是断连：Swoole 的协议层上限由启动期检查保证不小于本项。
        $sender = new FakeSender();
        $controller = $this->controller(sender: $sender, websocket: ['max_frame_bytes' => 16]);
        $this->open($controller, self::FD);

        $controller->onMessage(new stdClass(), $this->frame(self::FD, str_repeat('x', 17)));

        $this->assertStringContainsString('超过单帧上限 16 字节', $sender->pushed[0][1]);
    }

    public function testOnCloseLeavesEveryRoomUnregistersAndForgetsTheRateLimitWindow(): void
    {
        $store = new FakeWsConnectionStore();
        $roomStore = new FakeWsRoomStore();
        $sender = new FakeSender();
        $controller = $this->controller(
            store: $store,
            roomStore: $roomStore,
            sender: $sender,
            websocket: ['rate_limit' => ['window_seconds' => 3600, 'max_messages' => 1]],
        );
        $this->open($controller, self::FD);

        $session = WsSession::find(self::FD);
        $session?->joinRoom('room:1');
        $session?->joinRoom('room:2');

        // 用掉唯一的名额：onClose 不 forget 的话，下面这次会因为「同一窗口内第 2 条」被限流。
        $controller->onMessage(new stdClass(), $this->frame(self::FD, '{"id":"1","event":"sys.ping"}'));

        $controller->onClose(new stdClass(), self::FD, 1);

        $this->assertSame([['ws:' . self::FD, 'room:1'], ['ws:' . self::FD, 'room:2']], $roomStore->left);
        $this->assertSame(['ws:' . self::FD], $store->unregistered);
        // fd 会被内核复用：会话与连接级 Context 都必须清干净。
        $this->assertNull(WsSession::find(self::FD));

        $this->open($controller, self::FD);
        $controller->onMessage(new stdClass(), $this->frame(self::FD, '{"id":"2","event":"sys.ping"}'));

        $last = $sender->pushed[array_key_last($sender->pushed)];
        $this->assertStringContainsString('"code":0', $last[1]);
    }

    private function controller(
        ?FakeWsConnectionStore $store = null,
        ?FakeWsRoomStore $roomStore = null,
        ?FakeSender $sender = null,
        array $websocket = [],
    ): WsGatewayController {
        $store ??= new FakeWsConnectionStore();
        $roomStore ??= new FakeWsRoomStore();
        $sender ??= new FakeSender();
        $config = WsTestConfig::make($websocket);
        $limiter = new WsConnectionRateLimiter($config);
        $translator = new Translator(new FileLoader(new Filesystem(), BASE_PATH . '/runtime/lang'), 'zh_CN');

        return new TestGatewayController(
            new WsGatewayScanner($config),
            new WsDispatcher(
                new WsHandlerRegistry(),
                $store,
                $roomStore,
                new RequestDataHydrator(new ValidatorFactory($translator)),
                new FakePrincipalContext(),
                new MapContainer([]),
                $config,
                $limiter,
                StubLoggerFactory::create(),
            ),
            $store,
            $roomStore,
            $limiter,
            $sender,
            $config,
            StubLoggerFactory::create(),
        );
    }

    /**
     * 用例里不构造 Swoole 的握手请求，走 resolveFd() 的退化路径（连接级 Context）。
     */
    private function open(WsGatewayController $controller, int $fd): void
    {
        CoContext::set(ConnectionContext::FD, $fd);
        $controller->onOpen(new stdClass(), new stdClass());
    }

    private function frame(int $fd, string $data, int $opcode = WEBSOCKET_OPCODE_TEXT): object
    {
        return new class($fd, $data, $opcode) {
            public function __construct(
                public int $fd,
                public string $data,
                public int $opcode,
            ) {
            }
        };
    }
}

#[WsGateway(path: '/ws/hub', server: 'ws', endpoint: 'hub', name: '测试入口')]
final class TestGatewayController extends WsGatewayController
{
}
