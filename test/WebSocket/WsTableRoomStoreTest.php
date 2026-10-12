<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket;

use Hyperf\Apex\Test\WebSocket\Support\WsTestConfig;
use Hyperf\Apex\WebSocket\Registry\WsTableRoomStore;
use Hyperf\Apex\WebSocket\Support\RoomName;
use Hyperf\Apex\WebSocket\Support\TableLimits;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 房间索引（Swoole\Table 驱动）的成员语义。
 *
 * 三个容易漏的点：重复订阅必须幂等（否则一个客户端能把房间名额吃光）、最后一个成员离开必须
 * 把房间行删掉（否则「房间数」只增不减）、房间名与连接 id 超长必须在写入前拦住 ——
 * Swoole\Table 对超长只截断不报错，截断后的房间名会与别的房间撞行、截断后的连接 id 解析不出 fd。
 *
 * 表是静态共享内存结构（init() 幂等，容量配置只在首次生效），所以本文件所有用例共用同一份容量，
 * 每个用例开始时先 reset() 清空已有行。
 */
final class WsTableRoomStoreTest extends TestCase
{
    private const CAPACITY = 2;

    public function testJoinIsCappedByRoomMemberCapacity(): void
    {
        $store = $this->store();

        $this->assertTrue($store->joinRoom('ws:1', 'room:1'));
        $this->assertTrue($store->joinRoom('ws:2', 'room:1'));
        $this->assertFalse($store->joinRoom('ws:3', 'room:1'));
        $this->assertSame(['ws:1', 'ws:2'], $store->connectionsByRoom('room:1'));
    }

    public function testRepeatedJoinIsIdempotent(): void
    {
        $store = $this->store();
        $store->joinRoom('ws:1', 'room:1');

        // 重复订阅不该把第二个名额吃掉。
        $this->assertTrue($store->joinRoom('ws:1', 'room:1'));
        $this->assertTrue($store->joinRoom('ws:2', 'room:1'));
        $this->assertSame(['ws:1', 'ws:2'], $store->connectionsByRoom('room:1'));
    }

    public function testLeaveRemovesMemberAndDropsTheEmptyRoom(): void
    {
        $store = $this->store();
        $store->joinRoom('ws:1', 'room:1');
        $store->joinRoom('ws:2', 'room:1');

        $store->leaveRoom('ws:1', 'room:1');
        $this->assertSame(['ws:2'], $store->connectionsByRoom('room:1'));
        $this->assertSame(1, $store->countRooms());

        // 最后一个成员离开即房间不存在：留着空行会让「房间数」这个指标只增不减。
        $store->leaveRoom('ws:2', 'room:1');
        $this->assertSame([], $store->connectionsByRoom('room:1'));
        $this->assertSame(0, $store->countRooms());
    }

    public function testLeaveIsNoopOnUnknownRoomAndUnknownMember(): void
    {
        $store = $this->store();
        $store->joinRoom('ws:1', 'room:1');

        $store->leaveRoom('ws:1', 'room:none');
        $store->leaveRoom('ws:9', 'room:1');

        $this->assertSame(['ws:1'], $store->connectionsByRoom('room:1'));
        $this->assertSame(1, $store->countRooms());
    }

    public function testOversizedRoomNameIsRejectedBeforeTheKeyIsTruncated(): void
    {
        // 房间名是房间表的键，而 Swoole\Table 的键上限是 63 字节：超长只截断不报错，
        // 两个前 63 字节相同的房间名会静默落到同一行。
        $store = $this->store();

        try {
            $store->joinRoom('ws:1', str_repeat('r', RoomName::MAX_BYTES + 1));
            $this->fail('房间名超过键上限时必须抛异常。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('房间名', $exception->getMessage());
        }
    }

    public function testOversizedConnectionIdIsRejectedBeforeTheValueIsTruncated(): void
    {
        $store = $this->store();

        try {
            $store->joinRoom(str_repeat('s', TableLimits::CONNECTION_ID_BYTES + 1), 'room:1');
            $this->fail('连接 id 超过索引列上限时必须抛异常。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('连接 id', $exception->getMessage());
        }
    }

    private function store(): WsTableRoomStore
    {
        WsTableRoomStore::init(WsTestConfig::make([
            'room_member_capacity' => self::CAPACITY,
            'room_table_size' => 4,
        ]));
        WsTableRoomStore::reset();

        return new WsTableRoomStore();
    }
}
