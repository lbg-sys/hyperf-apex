<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket\Support;

use Hyperf\Apex\WebSocket\Contract\WsConnectionStoreInterface;
use Hyperf\Apex\WebSocket\Session\WsSession;
use RuntimeException;

/**
 * 连接索引替身：记录调用并允许按用例摆布返回值，覆盖派发、推送、回收三条路径。
 *
 * `registered` 是唯一带状态的集合（register / unregister / hasConnection 共用），
 * 推送侧的「房间成员自愈」用例依赖它来表达「连接行已经没了」。
 */
final class FakeWsConnectionStore implements WsConnectionStoreInterface
{
    public bool $joinRoomResult = true;

    public ?RuntimeException $joinRoomThrowable = null;

    /** @var list<array{0: string, 1: string}> */
    public array $joined = [];

    /** @var list<array{0: string, 1: string}> */
    public array $left = [];

    /** @var array<string, true> */
    public array $registered = [];

    /** @var list<array{0: string, 1: int}> */
    public array $touched = [];

    /** @var list<string> */
    public array $unregistered = [];

    /** @var list<string> connectionsByUser() 的返回值 */
    public array $userConnections = [];

    /** @var list<string> connectionsByRoom() 的返回值 */
    public array $roomConnections = [];

    /** @var list<string> connectionsByEndpoint() 的返回值 */
    public array $endpointConnections = [];

    /** @var list<string> allConnections() 的返回值 */
    public array $allConnectionIds = [];

    /** @var list<string> idleConnections() 的返回值 */
    public array $idle = [];

    /** @var list<int> idleConnections() 收到的判据（活跃时间上限） */
    public array $idleQueries = [];

    public int $connectionCount = 0;

    public int $userCount = 0;

    public int $roomCount = 0;

    public function register(WsSession $session): void
    {
        $this->registered[$session->id()] = true;
    }

    public function unregister(string $connectionId): void
    {
        $this->unregistered[] = $connectionId;
        unset($this->registered[$connectionId]);
    }

    public function hasConnection(string $connectionId): bool
    {
        return isset($this->registered[$connectionId]);
    }

    public function touch(string $connectionId, int $activeAt): void
    {
        $this->touched[] = [$connectionId, $activeAt];
    }

    public function idleConnections(int $idleBefore): array
    {
        $this->idleQueries[] = $idleBefore;

        return $this->idle;
    }

    public function connectionsByUser(string $userType, int $userId): array
    {
        return $this->userConnections;
    }

    public function connectionsByRoom(string $room): array
    {
        return $this->roomConnections;
    }

    public function connectionsByEndpoint(string $endpoint): array
    {
        return $this->endpointConnections;
    }

    public function allConnections(): array
    {
        return $this->allConnectionIds;
    }

    public function countConnections(): int
    {
        return $this->connectionCount;
    }

    public function countUsers(): int
    {
        return $this->userCount;
    }

    public function countRooms(): int
    {
        return $this->roomCount;
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
