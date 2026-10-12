<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Outbox;

use Hyperf\Apex\DTO\Attribute\ArrayOf;
use Hyperf\Apex\Outbox\DTO\OutboxMessageDTO;
use PHPUnit\Framework\TestCase;

final class OutboxMessageDTOTest extends TestCase
{
    public function testPayloadAsHydratesScalarFields(): void
    {
        $message = $this->message(payload: ['recordId' => 1, 'name' => 'demo']);

        $payload = $message->payloadAs(DemoPayloadDTO::class);

        $this->assertInstanceOf(DemoPayloadDTO::class, $payload);
        $this->assertSame(1, $payload->recordId);
        $this->assertSame('demo', $payload->name);
    }

    public function testPayloadAsHydratesNestedDto(): void
    {
        $message = $this->message(payload: ['buyer' => ['id' => 7, 'name' => '甲']]);

        $payload = $message->payloadAs(OrderPayloadDTO::class);

        $this->assertInstanceOf(BuyerDTO::class, $payload->buyer);
        $this->assertSame(7, $payload->buyer->id);
    }

    public function testPayloadAsHydratesListOfDto(): void
    {
        $message = $this->message(payload: ['items' => [['sku' => 'a'], ['sku' => 'b']]]);

        $payload = $message->payloadAs(ItemListPayloadDTO::class);

        $this->assertCount(2, $payload->items);
        $this->assertContainsOnlyInstancesOf(ItemDTO::class, $payload->items);
        $this->assertSame('b', $payload->items[1]->sku);
    }

    public function testPayloadAsCastsNumericStringToInt(): void
    {
        // 载荷是 JSON 读回的 array，数字字段可能仍是数字串，转型口径与请求水合一致。
        $message = $this->message(payload: ['recordId' => '42']);

        $this->assertSame(42, $message->payloadAs(DemoPayloadDTO::class)->recordId);
    }

    private function message(array $payload): OutboxMessageDTO
    {
        return new OutboxMessageDTO(
            id: 1,
            eventType: 'demo.created',
            scopeKey: 'scope:1',
            idempotencyKey: 'record-1',
            aggregateType: null,
            aggregateId: null,
            payload: $payload,
            attemptCount: 1,
            maxAttempts: 8,
        );
    }
}

final readonly class DemoPayloadDTO
{
    public function __construct(
        public int $recordId,
        public string $name = '',
    ) {
    }
}

final readonly class BuyerDTO
{
    public function __construct(
        public int $id,
        public string $name = '',
    ) {
    }
}

final readonly class OrderPayloadDTO
{
    public function __construct(
        public BuyerDTO $buyer,
    ) {
    }
}

final readonly class ItemDTO
{
    public function __construct(
        public string $sku,
    ) {
    }
}

final readonly class ItemListPayloadDTO
{
    public function __construct(
        #[ArrayOf(ItemDTO::class)]
        public array $items = [],
    ) {
    }
}
