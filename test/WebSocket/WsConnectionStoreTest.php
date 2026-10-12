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
 */
final class WsConnectionStoreTest extends TestCase
{
    public function testRegisterRollsBackConnectionRowWhenUserIndexIsFull(): void
    {
        WsConnectionStore::init(new Config([
            'websocket' => [
                'table_size' => 8,
                'max_connections_per_user' => 1,
                'room_member_capacity' => 4,
                'room_table_size' => 4,
            ],
        ]));

        $store = new WsConnectionStore();
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
}
