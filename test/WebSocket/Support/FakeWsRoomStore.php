<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket\Support;

use Hyperf\Apex\WebSocket\Contract\WsRoomStoreInterface;
use RuntimeException;

/**
 * 房间索引替身：记录调用并按用例摆布成员，覆盖派发、推送、回收三条路径。
 *
 * `members` 是唯一带状态的集合（connectionsByRoom 的返回值），
 * 推送侧的「房间成员自愈」用例依赖它表达「某个成员已经失效」。
 */
final class FakeWsRoomStore implements WsRoomStoreInterface
{
    public bool $joinRoomResult = true;

    public ?RuntimeException $joinRoomThrowable = null;

    /** @var list<array{0: string, 1: string}> */
    public array $joined = [];

    /** @var list<array{0: string, 1: string}> */
    public array $left = [];

    /** @var list<string> connectionsByRoom() 收到的房间名 */
    public array $queried = [];

    /** @var array<string, list<string>> 房间名 => 成员连接 id */
    public array $members = [];

    public int $roomCount = 0;

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

    public function connectionsByRoom(string $room): array
    {
        $this->queried[] = $room;

        return $this->members[$room] ?? [];
    }

    public function countRooms(): int
    {
        return $this->roomCount;
    }
}
