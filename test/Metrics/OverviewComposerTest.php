<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Metrics;

use Carbon\CarbonImmutable;
use Hyperf\Apex\Metrics\Collector\GaugeSample;
use Hyperf\Apex\Metrics\DTO\MetricsOverviewDTO;
use Hyperf\Apex\Metrics\Enum\AlertLevel;
use Hyperf\Apex\Metrics\Overview\OverviewComposer;
use Hyperf\Apex\Support\Time;
use Hyperf\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * 大屏数据的计算契约：增量、错误率、分位数、阈值判定。
 *
 * 分位数与阈值是这里唯一会算错的地方，所以用例都用整数与确定的上界，
 * 让「落在哪个桶」这件事没有解释空间。
 */
final class OverviewComposerTest extends TestCase
{
    protected function setUp(): void
    {
        CarbonImmutable::setTestNow('2026-10-09 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
    }

    public function testComputesRatesAndPercentilesFromTwoSamples(): void
    {
        $previous = [
            'core_http_requests_total' => ['2xx' => 100, '5xx' => 0],
            'core_http_request_duration_seconds' => [
                'le:0.01' => 100, 'le:0.1' => 190, 'le:1' => 200, '_sum' => 5_000_000, '_count' => 200,
            ],
        ];
        $current = [
            'core_http_requests_total' => ['2xx' => 180, '5xx' => 20],
            'core_http_request_duration_seconds' => [
                'le:0.01' => 160, 'le:0.1' => 280, 'le:1' => 300, '_sum' => 15_000_000, '_count' => 300,
            ],
        ];

        $overview = $this->compose($current, $previous);

        $this->assertTrue($overview->hasBaseline);
        $this->assertSame(10, $overview->intervalSeconds);
        $this->assertSame(100, $overview->requests);
        $this->assertSame(20, $overview->errors);
        $this->assertSame(0.2, $overview->errorRate);
        $this->assertSame(10.0, $overview->qps);

        // 本次 100 次观测、总耗时 10 秒 → 平均 0.1 秒。
        $this->assertSame(0.1, $overview->avgSeconds);
        // 名次 50 落在第一个累积桶（60）里，估计值就是它的上界。
        $this->assertSame(0.01, $overview->p50Seconds);
        // 名次 95 / 99 都落在最大的有限桶里。
        $this->assertSame(1.0, $overview->p95Seconds);
        $this->assertSame(1.0, $overview->p99Seconds);
    }

    public function testThresholdsDecideTheOverallStatus(): void
    {
        // 错误率 0.2 越过严重档（0.05），P95 1.0 只到警告档（0.5/2.0）。
        $current = [
            'core_http_requests_total' => ['2xx' => 80, '5xx' => 20],
            'core_http_request_duration_seconds' => [
                'le:1' => 100, '_sum' => 5_000_000, '_count' => 100,
            ],
        ];

        $overview = $this->compose($current, []);

        $levels = [];
        foreach ($overview->alerts as $alert) {
            $levels[$alert->key] = $alert->level;
        }

        $this->assertSame(AlertLevel::CRITICAL, $levels['error_rate']);
        $this->assertSame(AlertLevel::WARNING, $levels['p95_seconds']);
        $this->assertSame(AlertLevel::CRITICAL, $overview->status, '总判定取最差的那一档。');
    }

    public function testQuantileOutsideLastBucketIsUnknownInsteadOfFaked(): void
    {
        // 只有 3 次观测落在有限桶里，但 _count 是 10：名次 10 落在 +Inf 桶，
        // 拿最大上界冒充会让页面看起来「刚好卡在阈值上」。
        $current = [
            'core_http_request_duration_seconds' => [
                'le:0.01' => 1, 'le:0.1' => 2, 'le:1' => 3, '_sum' => 60_000_000, '_count' => 10,
            ],
        ];

        $overview = $this->compose($current, []);

        $this->assertNull($overview->p50Seconds);
        $this->assertNull($overview->p95Seconds);
        $this->assertNull($overview->p99Seconds);
        $this->assertSame(6.0, $overview->avgSeconds);
        $this->assertSame([], $overview->alerts, '算不出来的分位数不参与阈值判定。');
        $this->assertSame(AlertLevel::OK, $overview->status);
    }

    public function testGaugeBackedThresholdFires(): void
    {
        $gauges = [
            new GaugeSample('core_alert_backlog_seconds', '告警积压秒数', [], 400),
            new GaugeSample('core_websocket_connections', '在线连接数', ['endpoint' => '/ws/hub'], 12),
        ];

        $overview = $this->compose([], [], $gauges);

        $this->assertCount(1, $overview->alerts);
        $this->assertSame('alert_backlog_seconds', $overview->alerts[0]->key);
        $this->assertSame(AlertLevel::WARNING, $overview->alerts[0]->level);
        $this->assertSame(AlertLevel::WARNING, $overview->status);

        // 采样指标原样带给页面。
        $this->assertSame('core_alert_backlog_seconds', $overview->gauges[0]->name);
        $this->assertSame(['endpoint' => '/ws/hub'], $overview->gauges[1]->labels);
        $this->assertSame(12, $overview->gauges[1]->value);
    }

    public function testWithoutBaselineIntervalValuesStayEmpty(): void
    {
        $current = [
            'core_http_requests_total' => ['2xx' => 999],
            'core_http_request_duration_seconds' => ['le:1' => 999, '_sum' => 999_000_000, '_count' => 999],
        ];

        $overview = $this->compose($current, [], [new GaugeSample('core_x', 'x', [], 3)], previousAt: 0);

        $this->assertFalse($overview->hasBaseline);
        $this->assertSame(0, $overview->intervalSeconds);
        // 没有基准就没有「本次间隔」：累计值不能冒充增量，否则大屏第一眼就是个假峰值。
        $this->assertSame(0, $overview->requests);
        $this->assertSame(0.0, $overview->errorRate);
        $this->assertSame(0.0, $overview->qps);
        $this->assertNull($overview->p95Seconds);
        $this->assertSame([], $overview->alerts);
        $this->assertCount(1, $overview->gauges, '采样指标是即时值，不受基准影响。');
    }

    /**
     * @param array<string, array<string, int>> $current
     * @param array<string, array<string, int>> $previous
     * @param list<GaugeSample> $gauges
     */
    private function compose(array $current, array $previous, array $gauges = [], ?int $previousAt = null): MetricsOverviewDTO
    {
        $now = Time::now()->getTimestamp();

        return $this->composer()->compose($current, $previous, $previousAt ?? $now - 10, $gauges, $now);
    }

    private function composer(): OverviewComposer
    {
        return new OverviewComposer(new Config([
            'metrics' => [
                'overview' => [
                    'refresh_seconds' => 10,
                    'thresholds' => [
                        'error_rate' => ['warning' => 0.01, 'critical' => 0.05],
                        'p95_seconds' => ['warning' => 0.5, 'critical' => 2.0],
                        'alert_backlog_seconds' => ['warning' => 300, 'critical' => 900],
                        'pool_timeout' => ['warning' => 1, 'critical' => 5],
                    ],
                ],
            ],
        ]));
    }
}
