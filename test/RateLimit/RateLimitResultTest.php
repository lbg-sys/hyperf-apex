<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\RateLimit;

use Hyperf\Apex\RateLimit\RateLimitResult;
use PHPUnit\Framework\TestCase;

/**
 * 限流结果的响应头契约。
 *
 * `X-RateLimit-Limit` / `X-RateLimit-Remaining` 成功与拒绝都要给（客户端据此判断还剩多少额度）；
 * `Retry-After` 只在**被拒**且建议等待 > 0 时给 —— 放行时给个 0 会让客户端误以为要等。
 * 降级结果走同一个表达，调用方不需要区分「正常判定」与「Redis 挂了」。
 */
final class RateLimitResultTest extends TestCase
{
    public function testAllowedResultHasLimitAndRemainingWithoutRetryAfter(): void
    {
        $result = new RateLimitResult(allowed: true, limit: 60, remaining: 59, retryAfterSeconds: 0);

        $this->assertSame(
            ['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '59'],
            $result->headers(),
        );
    }

    public function testRejectedResultIncludesRetryAfter(): void
    {
        $result = new RateLimitResult(allowed: false, limit: 60, remaining: 0, retryAfterSeconds: 3);

        $this->assertSame(
            ['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '0', 'Retry-After' => '3'],
            $result->headers(),
        );
    }

    public function testDegradationUsesTheSameShape(): void
    {
        // fail-open：放行，额度信息不可信但给出桶容量，避免客户端当成「没有限流」。
        $open = RateLimitResult::degradeOpen(60);
        $this->assertTrue($open->allowed);
        $this->assertSame(['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '60'], $open->headers());

        // fail-close：拒绝，等一秒重试即可。
        $closed = RateLimitResult::degradeClosed(60);
        $this->assertFalse($closed->allowed);
        $this->assertSame(
            ['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '0', 'Retry-After' => '1'],
            $closed->headers(),
        );
    }
}
