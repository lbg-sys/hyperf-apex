<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Notify;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Hyperf\Apex\Notify\Channel\WecomRobotChannel;
use Hyperf\Apex\Notify\DTO\NotifyAttachmentDTO;
use Hyperf\Apex\Notify\DTO\NotifyMentionDTO;
use Hyperf\Apex\Notify\DTO\NotifyMessageDTO;
use Hyperf\Apex\Notify\DTO\NotifyTargetDTO;
use Hyperf\Apex\Notify\Enum\NotifyMessageFormat;
use Hyperf\Apex\Notify\Enum\NotifyOutcome;
use Hyperf\Apex\Notify\Enum\NotifySeverity;
use Hyperf\Config\Config;
use Hyperf\Guzzle\ClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * 企业微信群机器人渠道。
 *
 * 三条最容易做错的地方逐条钉住：目标地址校验（http / 域名 / key）、
 * `@` 能力差异导致的类型降级（markdown_v2 带 @ 只能降 text）、
 * 以及「HTTP 200 ≠ 发送成功，错误在响应体 errcode 里」的归一化。
 *
 * HTTP 用 Guzzle MockHandler 替身：目标与 payload 校验发生在请求之前，用空队列就能测
 * （真发了请求反而会因队列耗尽而失败）；errcode 归一化则按队列喂响应。
 */
final class WecomRobotChannelTest extends TestCase
{
    public function testCodeNameAndRateLimit(): void
    {
        $channel = $this->channel([]);

        $this->assertSame('wecom_robot', $channel->code());
        $this->assertSame('企业微信群机器人', $channel->name());
        $this->assertSame(20, $channel->maxMessagesPerMinute());
    }

    public function testNonHttpsWebhookIsRejected(): void
    {
        $result = $this->channel([])->send(
            new NotifyTargetDTO('http://weixin.qq.com/cgi-bin/webhook/send?key=abc'),
            $this->message(),
        );

        $this->assertSame(NotifyOutcome::PERMANENT, $result->outcome);
        $this->assertStringContainsString('必须使用 https', $result->errorMessage);
    }

    public function testNonWecomHostIsRejected(): void
    {
        $result = $this->channel([])->send(
            new NotifyTargetDTO('https://feishu.cn/cgi-bin/webhook/send?key=abc'),
            $this->message(),
        );

        $this->assertSame(NotifyOutcome::PERMANENT, $result->outcome);
        $this->assertStringContainsString('不是企业微信域名', $result->errorMessage);
    }

    public function testMissingKeyIsRejected(): void
    {
        $result = $this->channel([])->send(
            new NotifyTargetDTO('https://weixin.qq.com/cgi-bin/webhook/send'),
            $this->message(),
        );

        $this->assertSame(NotifyOutcome::PERMANENT, $result->outcome);
        $this->assertStringContainsString('缺少 key', $result->errorMessage);
    }

    public function testTextWithMentionsWritesMentionFields(): void
    {
        $history = [];
        $channel = $this->channel([$this->successResponse()], [], $history);

        $channel->send($this->target(), $this->message([
            'format' => NotifyMessageFormat::TEXT,
            'mentions' => new NotifyMentionDTO(all: true, mobiles: ['13800138000']),
        ]));

        $text = $this->payload($history)['text'];
        $this->assertSame(['@all'], $text['mentioned_list']);
        $this->assertSame(['13800138000'], $text['mentioned_mobile_list']);
    }

    public function testMarkdownV2WithMentionsDowngradesToText(): void
    {
        $history = [];
        $channel = $this->channel([$this->successResponse()], [], $history);

        $channel->send($this->target(), $this->message([
            'format' => NotifyMessageFormat::MARKDOWN_V2,
            'mentions' => new NotifyMentionDTO(userIds: ['alice']),
        ]));

        $payload = $this->payload($history);
        // v2 明确不支持 <@userid>，带 @ 只能降级为 text 的 mentioned_list 字段。
        $this->assertSame('text', $payload['msgtype']);
        $this->assertSame(['alice'], $payload['text']['mentioned_list']);
    }

    public function testMarkdownWithUserIdMentionsInlinesThem(): void
    {
        $history = [];
        $channel = $this->channel([$this->successResponse()], [], $history);

        $channel->send($this->target(), $this->message([
            'format' => NotifyMessageFormat::MARKDOWN,
            'mentions' => new NotifyMentionDTO(userIds: ['alice']),
        ]));

        $payload = $this->payload($history);
        // markdown 保留原类型，@ 内联进 content，排版不丢。
        $this->assertSame('markdown', $payload['msgtype']);
        $this->assertStringContainsString('<@alice>', $payload['markdown']['content']);
    }

    public function testImageWithoutAttachmentIsRejected(): void
    {
        $result = $this->channel([])->send($this->target(), $this->message([
            'format' => NotifyMessageFormat::IMAGE,
        ]));

        $this->assertSame(NotifyOutcome::PERMANENT, $result->outcome);
        $this->assertStringContainsString('缺少附件', $result->errorMessage);
    }

    public function testImageTooLargeIsRejected(): void
    {
        // 用配置把上限压小，不必真的造 2MB 载荷。
        $channel = $this->channel([], ['max_image_bytes' => 8]);

        $result = $channel->send($this->target(), $this->message([
            'format' => NotifyMessageFormat::IMAGE,
            'attachment' => new NotifyAttachmentDTO('binary-content'),
        ]));

        $this->assertSame(NotifyOutcome::PERMANENT, $result->outcome);
        $this->assertStringContainsString('超过上限', $result->errorMessage);
    }

    public function testNewsWithoutArticlesIsRejected(): void
    {
        $result = $this->channel([])->send($this->target(), $this->message([
            'format' => NotifyMessageFormat::NEWS,
        ]));

        $this->assertSame(NotifyOutcome::PERMANENT, $result->outcome);
        $this->assertStringContainsString('1~8 条', $result->errorMessage);
    }

    public function testFileWithoutNameIsRejected(): void
    {
        $result = $this->channel([])->send($this->target(), $this->message([
            'format' => NotifyMessageFormat::FILE,
            'attachment' => new NotifyAttachmentDTO('content'),
        ]));

        $this->assertSame(NotifyOutcome::PERMANENT, $result->outcome);
        $this->assertStringContainsString('缺少文件名', $result->errorMessage);
    }

    public function testErrcodeZeroIsSuccess(): void
    {
        $history = [];
        $channel = $this->channel([$this->successResponse()], [], $history);

        $result = $channel->send($this->target(), $this->message());

        $this->assertSame(NotifyOutcome::SUCCESS, $result->outcome);
        $this->assertSame('https://weixin.qq.com/cgi-bin/webhook/send?key=abc', (string) $history[0]['request']->getUri());
    }

    public function testErrcode45009IsThrottled(): void
    {
        $channel = $this->channel([new GuzzleResponse(200, [], '{"errcode":45009,"errmsg":"freq out of limit"}')]);

        $result = $channel->send($this->target(), $this->message());

        $this->assertSame(NotifyOutcome::THROTTLED, $result->outcome);
        $this->assertStringContainsString('企微限流', $result->errorMessage);
    }

    public function testHttp500IsRetryable(): void
    {
        $channel = $this->channel([new GuzzleResponse(500, [], 'boom')]);

        $result = $channel->send($this->target(), $this->message());

        $this->assertSame(NotifyOutcome::RETRYABLE, $result->outcome);
    }

    public function testUnknownErrcodeIsPermanent(): void
    {
        $channel = $this->channel([new GuzzleResponse(200, [], '{"errcode":93000,"errmsg":"invalid webhook"}')]);

        $result = $channel->send($this->target(), $this->message());

        // 机器人相关的错误码原因都在配置里，重试不会改变结果，取保守的不可重试。
        $this->assertSame(NotifyOutcome::PERMANENT, $result->outcome);
        $this->assertStringContainsString('93000', $result->errorMessage);
        $this->assertStringContainsString('webhook 地址不合法', $result->errorMessage);
    }

    /**
     * @param list<GuzzleResponse> $responses
     * @param array<string, mixed> $config
     * @param null|array<int, array{request: RequestInterface}> $history 出站请求历史，按引用回填
     */
    private function channel(array $responses, array $config = [], ?array &$history = null): WecomRobotChannel
    {
        $history = [];
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        // 历史按引用回填：请求在 send() 里才发出，而 channel() 先返回，直接按值返回会是空数组。
        $stack->push(Middleware::history($history));

        $client = new Client(['handler' => $stack]);
        $factory = new class($client) extends ClientFactory {
            public function __construct(private readonly Client $client)
            {
            }

            public function create(array $options = []): Client
            {
                return $this->client;
            }
        };

        return new WecomRobotChannel($factory, new Config(['notify' => ['wecom' => $config]]));
    }

    private function target(): NotifyTargetDTO
    {
        return new NotifyTargetDTO('https://weixin.qq.com/cgi-bin/webhook/send?key=abc');
    }

    /** @param array<string, mixed> $overrides */
    private function message(array $overrides = []): NotifyMessageDTO
    {
        return new NotifyMessageDTO(...array_merge([
            'format' => NotifyMessageFormat::TEXT,
            'title' => '告警',
            'content' => '内容',
            'severity' => NotifySeverity::WARNING,
        ], $overrides));
    }

    private function successResponse(): GuzzleResponse
    {
        return new GuzzleResponse(200, [], '{"errcode":0,"errmsg":"ok"}');
    }

    /**
     * @param array<int, array{request: RequestInterface}> $history
     * @return array<string, mixed>
     */
    private function payload(array $history): array
    {
        $body = (string) $history[0]['request']->getBody();

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}
