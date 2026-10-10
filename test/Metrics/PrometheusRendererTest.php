<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Metrics;

use Hyperf\Apex\Metrics\Collector\GaugeSample;
use Hyperf\Apex\Metrics\Enum\MetricType;
use Hyperf\Apex\Metrics\MetricSeries;
use Hyperf\Apex\Metrics\Renderer\PrometheusRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Prometheus 文本格式的契约。
 *
 * 三处最容易出错的地方：histogram 的桶必须是累积计数且带 `+Inf`、`_sum` 要从微秒换算回秒、
 * 整组为零的序列不能输出（否则每次抓取多传一份上兆文本）。
 */
final class PrometheusRendererTest extends TestCase
{
    public function testCounterSkipsZeroSeriesAndPrintsHeaderOnce(): void
    {
        $series = [
            new MetricSeries(1, 'core_x_total', MetricType::COUNTER, '总数', ['code' => 'a']),
            new MetricSeries(2, 'core_x_total', MetricType::COUNTER, '总数', ['code' => 'b']),
            new MetricSeries(3, 'core_y_total', MetricType::COUNTER, '另一个总数', []),
        ];
        $values = [1 => 0, 2 => 5, 3 => 0];

        $text = (new PrometheusRenderer())->render($series, $values, []);

        $this->assertSame(<<<'TEXT'
# HELP core_x_total 总数
# TYPE core_x_total counter
core_x_total{code="b"} 5

TEXT, $text);
    }

    public function testHistogramRendersCumulativeBucketsWithSumInSeconds(): void
    {
        $series = [
            new MetricSeries(1, 'core_h_seconds', MetricType::HISTOGRAM, '耗时', [], '_bucket', 0.1),
            new MetricSeries(2, 'core_h_seconds', MetricType::HISTOGRAM, '耗时', [], '_bucket', 1.0),
            new MetricSeries(3, 'core_h_seconds', MetricType::HISTOGRAM, '耗时', [], '_sum'),
            new MetricSeries(4, 'core_h_seconds', MetricType::HISTOGRAM, '耗时', [], '_count'),
        ];
        // 表里存的是微秒：1.5 秒 = 1500000。
        $values = [1 => 3, 2 => 7, 3 => 1_500_000, 4 => 9];

        $text = (new PrometheusRenderer())->render($series, $values, []);

        $this->assertSame(<<<'TEXT'
# HELP core_h_seconds 耗时
# TYPE core_h_seconds histogram
core_h_seconds_bucket{le="0.1"} 3
core_h_seconds_bucket{le="1"} 7
core_h_seconds_bucket{le="+Inf"} 9
core_h_seconds_sum 1.500000
core_h_seconds_count 9

TEXT, $text);
    }

    public function testAllZeroHistogramGroupIsOmitted(): void
    {
        $series = [
            new MetricSeries(1, 'core_h_seconds', MetricType::HISTOGRAM, '耗时', [], '_bucket', 0.1),
            new MetricSeries(2, 'core_h_seconds', MetricType::HISTOGRAM, '耗时', [], '_count'),
        ];

        $this->assertSame('', (new PrometheusRenderer())->render($series, [1 => 0, 2 => 0], []));
    }

    public function testGaugeIsAlwaysRenderedEvenWhenZero(): void
    {
        $gauges = [
            new GaugeSample('core_pool_connections', '连接池占用', ['pool' => 'default'], 0),
            new GaugeSample('core_pool_connections', '连接池占用', ['pool' => 'other'], 2),
        ];

        $text = (new PrometheusRenderer())->render([], [], $gauges);

        // 连接的 0 是有意义的（池空了），不能被跳零规则吃掉；HELP/TYPE 只出现一次，
        // 重复输出会让 Prometheus 拒绝整份抓取结果。
        $this->assertSame(<<<'TEXT'
# HELP core_pool_connections 连接池占用
# TYPE core_pool_connections gauge
core_pool_connections{pool="default"} 0
core_pool_connections{pool="other"} 2

TEXT, $text);
    }

    public function testLabelValuesAreEscaped(): void
    {
        $series = [
            new MetricSeries(1, 'core_x_total', MetricType::COUNTER, '总数', ['route' => "/a\"b\\c\nd"]),
        ];

        $text = (new PrometheusRenderer())->render($series, [1 => 1], []);

        $this->assertStringContainsString('core_x_total{route="/a\"b\\\c\nd"} 1', $text);
    }

    public function testEmptyInputRendersNothing(): void
    {
        $this->assertSame('', (new PrometheusRenderer())->render([], [], []));
    }
}
