<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\Test\Support\StubLoggerFactory;
use Hyperf\Apex\Test\WebSocket\Support\FakeSender;
use Hyperf\Apex\Test\WebSocket\Support\FakeWsConnectionStore;
use Hyperf\Apex\WebSocket\Service\WsPushService;
use PHPUnit\Framework\TestCase;

/**
 * 推送链路的行为契约。
 *
 * 两个容易写错的点：投递失败不等于连接已死（不能据此摘索引），以及房间成员里失效的连接
 * 需要一个能自愈的出口 —— 房间行只在 onClose 里摘，连接行先消失的窗口里没人清它。
 */
final class WsPushServiceTest extends TestCase
{
    public function testPushToUserSendsOneEnvelopePerConnection(): void
    {
        $store = new FakeWsConnectionStore();
        $store->userConnections = ['ws:1', 'ws:2'];
        $sender = new FakeSender();

        $this->service($store, $sender)->pushToUser('hub', 7, 'demo.created', new DemoPushPayloadDTO('A-1'));

        $this->assertSame([1, 2], array_column($sender->pushed, 0));
        $this->assertStringContainsString('demo.created', $sender->pushed[0][1]);
        $this->assertStringContainsString('A-1', $sender->pushed[1][1]);
    }

    public function testDeliveryFailureDoesNotDropTheConnectionIndex(): void
    {
        // Sender::push() 返回 false 只说明「这条投递没成功」：协程服务器下它同时也意味着
        // 「fd 不属于本 worker」。据此摘索引会把一条在线连接彻底踢出推送范围，且客户端毫无感知。
        $store = new FakeWsConnectionStore();
        $store->userConnections = ['ws:1'];
        $sender = new FakeSender();
        $sender->pushResult = false;

        $this->service($store, $sender)->pushToUser('hub', 7, 'demo.created', new DemoPushPayloadDTO('A-1'));

        $this->assertSame([], $store->unregistered);
    }

    public function testUnparsableConnectionIdIsDropped(): void
    {
        // 解析不出 fd 的 id 指向不了任何在线连接，摘掉是安全的。
        $store = new FakeWsConnectionStore();
        $store->userConnections = ['broken'];
        $sender = new FakeSender();

        $this->service($store, $sender)->pushToUser('hub', 7, 'demo.created', new DemoPushPayloadDTO('A-1'));

        $this->assertSame([], $sender->pushed);
        $this->assertSame(['broken'], $store->unregistered);
    }

    public function testPushToRoomPrunesMembersWhoseConnectionRowIsGone(): void
    {
        $store = new FakeWsConnectionStore();
        $store->roomConnections = ['ws:1', 'ws:2'];
        $store->registered = ['ws:2' => true];
        $sender = new FakeSender();

        $this->service($store, $sender)->pushToRoom('room:1', 'demo.created', new DemoPushPayloadDTO('A-1'));

        $this->assertSame([['ws:1', 'room:1']], $store->left);
        $this->assertSame([2], array_column($sender->pushed, 0));
    }

    public function testBroadcastSendsToEveryConnection(): void
    {
        $store = new FakeWsConnectionStore();
        $store->allConnectionIds = ['ws:1', 'ws:2', 'ws:3'];
        $sender = new FakeSender();

        $this->service($store, $sender)->broadcast('demo.created', new DemoPushPayloadDTO('A-1'));

        $this->assertSame([1, 2, 3], array_column($sender->pushed, 0));
    }

    private function service(FakeWsConnectionStore $store, FakeSender $sender): WsPushService
    {
        return new WsPushService($store, $sender, StubLoggerFactory::create());
    }
}

final readonly class DemoPushPayloadDTO extends AbstractDTO
{
    public function __construct(public string $recordId = '')
    {
    }
}
