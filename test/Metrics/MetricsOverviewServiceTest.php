<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Metrics;

use Hyperf\Apex\Metrics\Collector\GaugeCollectorInterface;
use Hyperf\Apex\Metrics\Collector\GaugeCollectorRegistry;
use Hyperf\Apex\Metrics\Collector\GaugeSample;
use Hyperf\Apex\Metrics\Overview\OverviewComposer;
use Hyperf\Apex\Metrics\Registry\MetricDefinitionRegistry;
use Hyperf\Apex\Metrics\Service\MetricsOverviewService;
use Hyperf\Apex\Metrics\Storage\MetricTable;
use Hyperf\Apex\Metrics\Storage\RateSampleTable;
use Hyperf\Config\Config;
use Hyperf\Contract\ConfigInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * 大屏数据的服务层契约：读本次聚合 + 上次基准 → 算 → **把本次写回基准**。
 *
 * 基准只在有人看大屏时才更新（这次请求就是「有人看」），所以「第一次看没有速率、第二次才有」
 * 这条行为必须由服务层保证 —— 少了写回，大屏永远显示 0；把累计值当增量，第一眼就是个假峰值。
 *
 * 用的是 `publish/metrics.php` 的真实定义（含 `@http_routes` 这类动态值域），
 * 因此也顺带验证了「配置 → 行展开 → 取值」这条链路能对上。
 */
final class MetricsOverviewServiceTest extends TestCase
{
    protected function setUp(): void
    {
        MetricTable::reset();
        RateSampleTable::reset();
        RateSampleTable::init();
    }

    protected function tearDown(): void
    {
        MetricTable::reset();
        RateSampleTable::reset();
    }

    public function testFirstViewHasNoRatesButRecordsTheBaseline(): void
    {
        MetricTable::init($this->registry(), 65536);
        MetricTable::add($this->seriesId('2xx'), 10);

        $overview = $this->service()->overview();

        // 没有基准就没有「本次间隔」，增量类数值一律按不可用处理，
        // 而不是把服务启动至今的累计值当成这一段的增量。
        $this->assertFalse($overview->hasBaseline);
        $this->assertSame(0, $overview->requests);
        $this->assertSame(0.0, $overview->qps);

        $baseline = RateSampleTable::read();
        $this->assertGreaterThan(0, $baseline['at']);
        $this->assertArrayHasKey('core_http_requests_total', $baseline['values']);
    }

    public function testSecondViewComputesRatesFromTheRecordedBaseline(): void
    {
        MetricTable::init($this->registry(), 65536);
        MetricTable::add($this->seriesId('2xx'), 100);

        $service = $this->service();
        $service->overview();

        // time() 在用例里不会自己走，把基准时间往前挪 10 秒来造出真实间隔。
        $baseline = RateSampleTable::read();
        RateSampleTable::write(time() - 10, $baseline['values']);

        MetricTable::add($this->seriesId('2xx'), 20);
        MetricTable::add($this->seriesId('5xx'), 2);

        $overview = $service->overview();

        $this->assertTrue($overview->hasBaseline);
        $this->assertSame(22, $overview->requests);
        $this->assertSame(2, $overview->errors);
        // 间隔恰好 10 秒（可能多走 1 秒），所以用容差断言。
        $this->assertEqualsWithDelta(2.2, $overview->qps, 0.25);
        $this->assertGreaterThan(0.0, $overview->errorRate);

        // 本次结果写回基准，下一次再看得就是这一段的增量。
        $this->assertGreaterThanOrEqual($baseline['at'], RateSampleTable::read()['at']);
    }

    public function testEnabledRequiresBothSwitchesAndAnInitialisedTable(): void
    {
        MetricTable::init($this->registry(), 65536);
        $this->assertTrue($this->service()->enabled());

        $this->assertFalse(
            $this->service(['overview' => ['enabled' => false]])->enabled(),
            '关闭大屏开关后就不该再算。'
        );

        MetricTable::reset();
        $this->assertFalse($this->service()->enabled(), '表没建起来（未初始化）时不该给出大屏数据。');
    }

    public function testGaugesComeFromCollectorsAndOneBrokenCollectorDoesNotEmptyThePage(): void
    {
        MetricTable::init($this->registry(), 65536);

        $service = $this->service(null, [WorkingCollector::class, BrokenCollector::class]);
        $overview = $service->overview();

        // 坏掉的采集器只丢它自己的样本：一个坏采集器不该让整页数据变空。
        $this->assertCount(1, $overview->gauges);
        $this->assertSame('worker_memory_usage_bytes', $overview->gauges[0]->name);
        $this->assertSame(1024, $overview->gauges[0]->value);
    }

    /**
     * @param null|array<string, mixed> $overrides 覆盖 metrics 配置（浅合并到对应子键）
     * @param list<class-string> $collectors
     */
    private function service(?array $overrides = null, array $collectors = []): MetricsOverviewService
    {
        $config = $this->config($overrides, $collectors);

        return new MetricsOverviewService(
            $config,
            new GaugeCollectorRegistry($this->container(), $config),
            new OverviewComposer($config),
        );
    }

    /**
     * @param null|array<string, mixed> $overrides
     * @param list<class-string> $collectors
     */
    private function config(?array $overrides, array $collectors): ConfigInterface
    {
        /** @var array<string, mixed> $template */
        $template = require BASE_PATH . '/packages/metrics/publish/metrics.php';

        if ($collectors !== []) {
            $template['collectors'] = $collectors;
        }

        foreach ($overrides ?? [] as $key => $value) {
            $template[$key] = is_array($value) && is_array($template[$key] ?? null)
                ? array_merge($template[$key], $value)
                : $value;
        }

        return new Config(['metrics' => $template]);
    }

    private function registry(): MetricDefinitionRegistry
    {
        // route 是动态值域，不给值这一维就展开不出任何行。
        return MetricDefinitionRegistry::fromConfig($this->config(null, []), ['http_routes' => ['/fixture']]);
    }

    /**
     * 取某个状态码段对应的指标行 id。
     */
    private function seriesId(string $statusClass): int
    {
        foreach ($this->registry()->series() as $series) {
            if ($series->name === 'core_http_requests_total' && ($series->labels['status_class'] ?? null) === $statusClass) {
                return $series->id;
            }
        }

        $this->fail(sprintf('指标定义里应当有 status_class=%s 的行。', $statusClass));
    }

    /**
     * 采集器注册表用：只按类名取实例。
     */
    private function container(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                return match ($id) {
                    WorkingCollector::class => new WorkingCollector(),
                    BrokenCollector::class => new BrokenCollector(),
                    default => throw new RuntimeException('容器里没有 ' . $id),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [WorkingCollector::class, BrokenCollector::class], true);
            }
        };
    }
}

final class WorkingCollector implements GaugeCollectorInterface
{
    public function collect(): array
    {
        return [new GaugeSample('worker_memory_usage_bytes', 'worker 内存占用', [], 1024)];
    }
}

final class BrokenCollector implements GaugeCollectorInterface
{
    public function collect(): array
    {
        throw new RuntimeException('采集器坏了');
    }
}
