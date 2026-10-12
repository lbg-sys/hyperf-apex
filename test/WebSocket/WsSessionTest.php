<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket;

use Hyperf\Apex\Security\Principal;
use Hyperf\Apex\WebSocket\Session\WsSession;
use Hyperf\Context\Context as CoContext;
use Hyperf\WebSocketServer\Context as ConnectionContext;
use PHPUnit\Framework\TestCase;

/**
 * 会话视图与连接 id 编解码。
 *
 * 连接 id 的编解码是连接索引与推送共用的契约（写入时拼 `{server}:{fd}`，推送时再拆回 fd），
 * 拆错的表现是「推送静默不发」；会话存放位置是**连接级** Context（按 fd 索引），
 * 因为 onOpen / onMessage / onClose 不在同一个协程里，协程 Context 在它们之间不共享。
 */
final class WsSessionTest extends TestCase
{
    private const FD = 7001;

    protected function tearDown(): void
    {
        WsSession::clear(self::FD);
    }

    public function testConnectionIdEmbedsTheServerName(): void
    {
        // server 维度必须带上：不同 server 的 fd 编号空间是独立的。
        $this->assertSame('ws:12', WsSession::connectionId('ws', 12));
    }

    public function testFdIsParsedAfterTheLastColon(): void
    {
        $this->assertSame(12, WsSession::fdOf('ws:12'));
        // server 名里也可能出现冒号：fd 一定在最后一个冒号之后。
        $this->assertSame(12, WsSession::fdOf('a:b:12'));
    }

    public function testUnparsableConnectionIdsYieldZero(): void
    {
        // 0 是「这条索引已经坏了」的约定值，推送与回收据此摘掉它。
        $this->assertSame(0, WsSession::fdOf('ws'));
        $this->assertSame(0, WsSession::fdOf('ws:'));
        $this->assertSame(0, WsSession::fdOf('ws:abc'));
        $this->assertSame(0, WsSession::fdOf('ws:12x'));
    }

    public function testRoomsAreUniqueAndKeepInsertionOrder(): void
    {
        $session = $this->session();

        $session->joinRoom('b');
        $session->joinRoom('a');
        $session->joinRoom('b');

        $this->assertSame(['b', 'a'], $session->rooms());
        $this->assertTrue($session->inRoom('a'));
        $this->assertFalse($session->inRoom('c'));

        $session->leaveRoom('b');
        $this->assertSame(['a'], $session->rooms());

        // 退订一个不在集合里的房间不该改动集合。
        $session->leaveRoom('c');
        $this->assertSame(['a'], $session->rooms());
    }

    public function testSessionIsReadableFromEveryCallbackByFd(): void
    {
        CoContext::set(ConnectionContext::FD, self::FD);
        $session = $this->session();
        $session->store();

        // onMessage / onClose 是另外的协程，只能按 fd 拿会话。
        $this->assertSame($session, WsSession::find(self::FD));
        $this->assertNull(WsSession::find(self::FD + 1));
    }

    public function testClearPreventsAFdFromInheritingThePreviousIdentity(): void
    {
        CoContext::set(ConnectionContext::FD, self::FD);
        $principal = new Principal(userId: 7, userType: 'hub', username: 'hub-7', sessionId: 's-7');

        $this->session()->store();
        WsSession::clear(self::FD);
        $this->assertNull(WsSession::find(self::FD));

        // 内核会复用 fd：清干净之后新连接读到的必须是自己的会话。
        $next = WsSession::open('ws', self::FD, '/ws/hub', $principal, 2000);
        $next->store();
        $this->assertSame($next, WsSession::find(self::FD));
        $this->assertSame(7, $next->principal?->userId);
    }

    private function session(): WsSession
    {
        return WsSession::open('ws', self::FD, '/ws/hub', null, 1_700_000_000);
    }
}
