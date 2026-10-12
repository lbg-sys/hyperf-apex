<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\WebSocket\Protocol\WsEnvelopeDTO;
use Hyperf\Apex\WebSocket\Protocol\WsPushDTO;
use Hyperf\Apex\WebSocket\Protocol\WsResponseDTO;
use PHPUnit\Framework\TestCase;

/**
 * 信封的线上形状。
 *
 * 这三个结构是协议本身：字段名、缺省值、响应与推送的区分方式（有没有 type），
 * 一旦漂移就是全端不兼容。所以这里断言的是**序列化后的完整数组**，而不是单个属性。
 */
final class WsProtocolDTOTest extends TestCase
{
    public function testEnvelopeAcceptsAWellFormedRequest(): void
    {
        $envelope = WsEnvelopeDTO::fromArray(['id' => '1', 'event' => 'demo.created', 'data' => ['recordId' => 'A-1']]);

        $this->assertSame('1', $envelope->replyId());
        $this->assertSame('demo.created', $envelope->event);
        $this->assertSame(['recordId' => 'A-1'], $envelope->data);
    }

    public function testEnvelopeTreatsAMissingIdAsAnEmptyReplyId(): void
    {
        // 客户端靠 id 匹配请求与响应；没给就回空串，而不是凭空造一个。
        $this->assertSame('', WsEnvelopeDTO::fromArray(['event' => 'sys.ping'])->replyId());
    }

    public function testEnvelopeTreatsEmptyDataAsNoData(): void
    {
        // `{}` 与 `[]` 在 json_decode 之后无法区分，只能都当成「没有数据」放行。
        $this->assertSame([], WsEnvelopeDTO::fromArray(['event' => 'sys.ping', 'data' => []])->data);
        $this->assertSame([], WsEnvelopeDTO::fromArray(['event' => 'sys.ping'])->data);
    }

    public function testResponseEnvelopeHasNoTypeAndCarriesCodeAndMessage(): void
    {
        $json = json_decode(WsResponseDTO::success('1', 'demo.created', new EnvelopePayloadDTO('A-1'))->toJson(), true);

        $this->assertSame([
            'id' => '1',
            'event' => 'demo.created',
            'code' => ErrorCode::SUCCESS->value,
            'message' => ErrorCode::SUCCESS->getMessage(),
            'data' => ['recordId' => 'A-1'],
        ], $json);
    }

    public function testErrorEnvelopeKeepsTheExplicitMessageAndDetailData(): void
    {
        $response = WsResponseDTO::error('9', 'demo.created', ErrorCode::RESOURCE_CONFLICT, '房间满了', ['errors' => ['x']]);

        $this->assertSame([
            'id' => '9',
            'event' => 'demo.created',
            'code' => ErrorCode::RESOURCE_CONFLICT->value,
            'message' => '房间满了',
            'data' => ['errors' => ['x']],
        ], json_decode($response->toJson(), true));
    }

    public function testErrorEnvelopeFallsBackToTheEnumMessage(): void
    {
        // 不给文案时用 ErrorCode 的中文含义：调用点不必到处重复同一句话。
        $response = WsResponseDTO::error('', 'sys.error', ErrorCode::INVALID_ARGUMENT);

        $this->assertSame(ErrorCode::INVALID_ARGUMENT->value, $response->code);
        $this->assertSame(ErrorCode::INVALID_ARGUMENT->getMessage(), $response->message);
        $this->assertSame([], $response->data);
    }

    public function testPushEnvelopeIsMarkedAsPushAndGetsAFreshId(): void
    {
        // 推送不是任何请求的应答，所以没有 code / message，只有 type 与自生成的 id
        // （供客户端去重与日志关联）。id 必须每次不同，否则客户端去重会把后一条丢掉。
        $first = WsPushDTO::make('demo.created', new EnvelopePayloadDTO('A-1'));
        $second = WsPushDTO::make('demo.created', new EnvelopePayloadDTO('A-1'));

        $this->assertSame(WsPushDTO::TYPE_PUSH, $first->type);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame([
            'id' => $first->id,
            'event' => 'demo.created',
            'type' => 'push',
            'data' => ['recordId' => 'A-1'],
        ], json_decode($first->toJson(), true));
    }
}

final readonly class EnvelopePayloadDTO extends AbstractDTO
{
    public function __construct(public string $recordId = '')
    {
    }
}
