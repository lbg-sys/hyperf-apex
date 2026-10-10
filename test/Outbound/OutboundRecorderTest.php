<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Outbound;

use Hyperf\Apex\Outbound\OutboundRecorder;
use PHPUnit\Framework\TestCase;

/**
 * 出站指标的两个纯函数。
 *
 * `resultFromStatus` 是跨出站目标统一的三档口径：只有限流单独成档 —— 它意味着对端在限流，
 * 而不是链路坏了。
 */
final class OutboundRecorderTest extends TestCase
{
    public function testResultFromStatus(): void
    {
        $this->assertSame(OutboundRecorder::RESULT_SUCCESS, OutboundRecorder::resultFromStatus(200));
        $this->assertSame(OutboundRecorder::RESULT_SUCCESS, OutboundRecorder::resultFromStatus(302));
        $this->assertSame(OutboundRecorder::RESULT_THROTTLED, OutboundRecorder::resultFromStatus(429));
        $this->assertSame(OutboundRecorder::RESULT_FAILURE, OutboundRecorder::resultFromStatus(400));
        $this->assertSame(OutboundRecorder::RESULT_FAILURE, OutboundRecorder::resultFromStatus(500));
    }

    public function testSecondsSinceConvertsNanosToSeconds(): void
    {
        // hrtime(true) 是纳秒，secondsSince 要把它折成秒（保留 4 位）。
        $oneSecondAgo = hrtime(true) - (int) 1e9;

        $this->assertEqualsWithDelta(1.0, OutboundRecorder::secondsSince($oneSecondAgo), 0.01);
    }
}
