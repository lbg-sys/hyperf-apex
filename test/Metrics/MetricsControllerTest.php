<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Metrics;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Http\ClientIpResolver;
use Hyperf\Apex\Http\IpRuleMatcher;
use Hyperf\Apex\Metrics\Access\MetricsAccessGuard;
use Hyperf\Apex\Metrics\Collector\GaugeCollectorRegistry;
use Hyperf\Apex\Metrics\Controller\MetricsController;
use Hyperf\Apex\Metrics\Overview\OverviewComposer;
use Hyperf\Apex\Metrics\Registry\MetricDefinitionRegistry;
use Hyperf\Apex\Metrics\Renderer\PrometheusRenderer;
use Hyperf\Apex\Metrics\Service\MetricsOverviewService;
use Hyperf\Apex\Metrics\Service\MetricsSnapshotService;
use Hyperf\Apex\Metrics\Storage\MetricTable;
use Hyperf\Apex\Metrics\Storage\RateSampleTable;
use Hyperf\Apex\Test\Metrics\Support\MetricsConfig;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Server\Response as PsrResponse;
use Hyperf\HttpServer\Response;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * 指标端点的装配契约。
 *
 * 三个端点共用一份来源白名单，且**不做身份鉴权**（抓取带不了业务凭证、大屏也只是一次 GET），
 * 所以这里钉住的是「门禁与开关的先后顺序」和「拒绝时不多说」：
 * - 开关关闭时直接 404，连白名单都不看（404 不该透露「本来允许谁访问」）；
 * - 来源不在白名单时 403，且响应里不回显来源 IP；
 * - 抓取用 Prometheus 自己的 content type，不是裸 text/plain；大屏返回随包发布的页面。
 */
final class MetricsControllerTest extends TestCase
{
    protected function setUp(): void
    {
        MetricTable::reset();
        RateSampleTable::reset();
        RateSampleTable::init();
        // 三个端点都以「表已建起来」为前提（表在启动期创建），默认按可用状态准备。
        MetricTable::init($this->registry(), 65536);
    }

    protected function tearDown(): void
    {
        MetricTable::reset();
        RateSampleTable::reset();
    }

    public function testScrapeIsNotFoundWhenMetricsAreDisabled(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('指标未启用');

        // 关掉开关时连白名单都不看：404 与「有没有权限」无关，不该泄露门禁的存在。
        $this->controller(['enabled' => false])->scrape($this->request('127.0.0.1'));
    }

    public function testScrapeRejectsDisallowedSourceWithoutEchoingTheAddress(): void
    {
        try {
            $this->controller(['allow_ips' => ['10.1.0.0/16']])->scrape($this->request('203.0.113.9'));
            $this->fail('不在白名单里的来源应当被拒绝。');
        } catch (BusinessException $exception) {
            $this->assertSame(ErrorCode::PERMISSION_DENIED->value, $exception->getErrorCode());
            // 不回显来源 IP：响应本身是给请求方看的，写进去等于多送一条信息。
            $this->assertStringNotContainsString('203.0.113.9', $exception->getMessage());
        }
    }

    public function testScrapeReturnsPrometheusTextWithItsOwnContentType(): void
    {
        MetricTable::add((int) $this->requestsSeriesId(), 5);

        $response = $this->controller(['allow_ips' => ['127.0.0.1']])->scrape($this->request('127.0.0.1'));

        // 抓取格式要用 Prometheus 自己声明的 content type，而不是裸 text/plain。
        $this->assertStringContainsString('text/plain; version=0.0.4', $response->getHeaderLine('content-type'));

        $body = (string) $response->getBody();
        $this->assertStringContainsString('# TYPE core_http_requests_total counter', $body);
        $this->assertStringContainsString('core_http_requests_total{', $body);
    }

    public function testDashboardIsNotFoundWhenTheOverviewIsDisabled(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('运维大屏未启用');

        $this->controller(['overview' => ['enabled' => false]])->dashboard($this->request('127.0.0.1'));
    }

    public function testDashboardServesTheBundledPage(): void
    {
        $response = $this->controller(['allow_ips' => ['127.0.0.1']])->dashboard($this->request('127.0.0.1'));

        $this->assertStringContainsString('text/html', $response->getHeaderLine('content-type'));
        // 页面是随包发布的静态文件：它自己会去轮询 /metrics/overview。
        $this->assertStringContainsString('/metrics/overview', (string) $response->getBody());
    }

    public function testOverviewReturnsTheComposedData(): void
    {
        $overview = $this->controller(['allow_ips' => ['127.0.0.1']])->overview($this->request('127.0.0.1'));

        // 表刚建好、还没有人看过：没有基准，刷新间隔来自配置。
        $this->assertFalse($overview->hasBaseline);
        $this->assertSame(10, $overview->refreshSeconds);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function controller(array $overrides): MetricsController
    {
        $config = MetricsConfig::from($overrides);

        return new MetricsController(
            new MetricsAccessGuard($config, new ClientIpResolver($config, new IpRuleMatcher()), new IpRuleMatcher()),
            new MetricsSnapshotService(new PrometheusRenderer(), new GaugeCollectorRegistry($this->container(), $config), $config),
            new MetricsOverviewService($config, new GaugeCollectorRegistry($this->container(), $config), new OverviewComposer($config)),
            new Response(new PsrResponse()),
        );
    }

    private function request(string $remoteAddr): Request
    {
        return (new Request('GET', '/metrics'))->withServerParams(['remote_addr' => $remoteAddr]);
    }

    private function registry(): MetricDefinitionRegistry
    {
        return MetricDefinitionRegistry::fromConfig(MetricsConfig::from(), ['http_routes' => ['/fixture']]);
    }

    private function requestsSeriesId(): ?int
    {
        foreach ($this->registry()->series() as $series) {
            if ($series->name === 'core_http_requests_total') {
                return $series->id;
            }
        }

        $this->fail('指标定义里应当有 core_http_requests_total。');
    }

    /**
     * 采集器注册表用：本组用例不关心采样指标，容器里什么都取不到即可。
     */
    private function container(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new RuntimeException('容器里没有 ' . $id);
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }
}
