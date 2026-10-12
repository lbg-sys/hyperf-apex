<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket;

use Hyperf\Apex\WebSocket\RateLimit\WsConnectionRateLimiter;
use Hyperf\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * 连接级限流的窗口语义。
 *
 * 时钟由调用方传入而不是内部取 time()，所以这里能精确断言「窗口边界」这种最容易写错的地方：
 * 用固定时间戳跑，不存在跨秒的偶发失败。
 */
final class WsConnectionRateLimiterTest extends TestCase
{
    public function testAllowsUpToTheLimitWithinOneWindow(): void
    {
        $limiter = $this->limiter(maxMessages: 2, windowSeconds: 60);

        $this->assertTrue($limiter->allow('ws:1', 1000));
        $this->assertTrue($limiter->allow('ws:1', 1000));
        $this->assertFalse($limiter->allow('ws:1', 1000));
    }

    public function testWindowResetsOnBoundary(): void
    {
        $limiter = $this->limiter(maxMessages: 1, windowSeconds: 60);

        // 窗口号是 intdiv(now, 60)：1000 与 1060 落在不同窗口，计数必须重新开始。
        $this->assertTrue($limiter->allow('ws:1', 1000));
        $this->assertFalse($limiter->allow('ws:1', 1000));
        $this->assertTrue($limiter->allow('ws:1', 1060));
    }

    public function testCountersArePerConnection(): void
    {
        $limiter = $this->limiter(maxMessages: 1, windowSeconds: 60);

        $this->assertTrue($limiter->allow('ws:1', 1000));
        $this->assertTrue($limiter->allow('ws:2', 1000));
    }

    public function testForgetClearsState(): void
    {
        $limiter = $this->limiter(maxMessages: 1, windowSeconds: 60);
        $limiter->allow('ws:1', 1000);

        // 连接关闭时必须能清干净，否则已关闭连接的条目会常驻进程内存。
        $limiter->forget('ws:1');

        $this->assertTrue($limiter->allow('ws:1', 1000));
    }

    public function testZeroLimitMeansUnlimited(): void
    {
        $limiter = $this->limiter(maxMessages: 0, windowSeconds: 60);

        $this->assertTrue($limiter->allow('ws:1', 1000));
        $this->assertTrue($limiter->allow('ws:1', 1000));
    }

    public function testMessageMentionsConfiguredLimit(): void
    {
        $this->assertSame('消息发送过于频繁：每 60 秒最多 2 条。', $this->limiter(maxMessages: 2, windowSeconds: 60)->message());
    }

    private function limiter(int $maxMessages, int $windowSeconds): WsConnectionRateLimiter
    {
        return new WsConnectionRateLimiter(new Config([
            'websocket' => [
                'rate_limit' => [
                    'window_seconds' => $windowSeconds,
                    'max_messages' => $maxMessages,
                ],
            ],
        ]));
    }
}
