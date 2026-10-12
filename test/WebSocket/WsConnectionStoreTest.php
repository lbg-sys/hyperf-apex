<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket;

use Hyperf\Apex\Security\Principal;
use Hyperf\Apex\WebSocket\Registry\WsConnectionStore;
use Hyperf\Apex\WebSocket\Session\WsSession;
use Hyperf\Config\Config;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 连接注册是「写连接行 + 写用户索引」两步，只有两步都成立才算注册成功。
 *
 * 失败时若留下连接行，调用方清掉会话后就再没人能清它：onClose 找不到会话会跳过
 * unregister()，脏行会一直挂在表里，广播与接入端成员列表持续向已关闭的 fd 投递。
 *
 * 表是静态共享内存结构（`init()` 幂等，配置只在首次生效），因此每个用例开始时先清空已有行，
 * 用例之间才互不影响。
 */
final class WsConnectionStoreTest extends TestCase
{
    public function testRegisterRollsBackConnectionRowWhenUserIndexIsFull(): void
    {
        $store = $this->store();
        $principal = new Principal(userId: 7, userType: 'hub', username: 'hub-7', sessionId: 'session-7');

        $store->register(WsSession::open('ws', 1, '/ws/hub', $principal, time()));
        $this->assertSame(['ws:1'], $store->allConnections());
        $this->assertSame(['ws:1'], $store->connectionsByUser('hub', 7));

        try {
            $store->register(WsSession::open('ws', 2, '/ws/hub', $principal, time()));
            $this->fail('超出单用户连接上限时 register() 必须抛异常。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('超过上限', $exception->getMessage());
        }

        $this->assertSame(['ws:1'], $store->allConnections());
        $this->assertSame(1, $store->countConnections());
        $this->assertSame(['ws:1'], $store->connectionsByEndpoint('/ws/hub'));
    }

    public function testTouchMovesTheConnectionOutOfIdleSet(): void
    {
        $store = $this->store();
        $store->register(WsSession::open('ws', 1, '/ws/hub', null, 1000));

        $store->touch('ws:1', 1200);

        $this->assertSame([], $store->idleConnections(1100));
        $this->assertSame(['ws:1'], $store->idleConnections(1201));
    }

    public function testTouchOnMissingConnectionDoesNotCreateRow(): void
    {
        // Swoole\Table::set() 对不存在的键会新建一行：touch 必须先确认连接还在，
        // 否则会把已经注销的连接重新变回「索引里的行」，从此再也没有人回收它。
        $store = $this->store();

        $store->touch('ws:9', 1200);

        $this->assertFalse($store->hasConnection('ws:9'));
        $this->assertSame([], $store->allConnections());
    }

    public function testIdleConnectionsReturnsOnlyStaleOnes(): void
    {
        $store = $this->store();
        $store->register(WsSession::open('ws', 1, '/ws/hub', null, 1000));
        $store->register(WsSession::open('ws', 2, '/ws/hub', null, 1000));
        $store->touch('ws:2', 1300);

        $this->assertSame(['ws:1'], $store->idleConnections(1200));
    }

    public function testHasConnectionFollowsUnregister(): void
    {
        $store = $this->store();
        $store->register(WsSession::open('ws', 1, '/ws/hub', null, 1000));

        $this->assertTrue($store->hasConnection('ws:1'));

        $store->unregister('ws:1');

        $this->assertFalse($store->hasConnection('ws:1'));
    }

    private function store(): WsConnectionStore
    {
        WsConnectionStore::init(self::config());

        $store = new WsConnectionStore();
        foreach ($store->allConnections() as $connectionId) {
            $store->unregister($connectionId);
        }

        return $store;
    }

    private static function config(): Config
    {
        return new Config([
            'websocket' => [
                'table_size' => 8,
                'max_connections_per_user' => 1,
                'room_member_capacity' => 4,
                'room_table_size' => 4,
            ],
        ]);
    }
}
