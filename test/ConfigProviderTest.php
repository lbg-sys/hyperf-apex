<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test;

use Hyperf\Apex\ApiDoc\Apifox\Command\GenerateApifoxDocCommand;
use Hyperf\Apex\ApiDoc\Apifox\ConfigProvider as ApiDocApifoxConfigProvider;
use Hyperf\Apex\ApiDoc\Command\GenerateApiDocCommand;
use Hyperf\Apex\ApiDoc\ConfigProvider as ApiDocConfigProvider;
use Hyperf\Apex\ConfigProvider as CoreConfigProvider;
use Hyperf\Apex\Database\Command\SchemaModelCheckCommand;
use Hyperf\Apex\Listener\DbQueryExecutedListener;
use Hyperf\Apex\Listener\EnvironmentConfigCheckListener;
use Hyperf\Apex\Listener\ResumeExitCoordinatorListener;
use Hyperf\Apex\Metrics\ConfigProvider as MetricsConfigProvider;
use Hyperf\Apex\Metrics\Listener\MetricTableInitListener;
use Hyperf\Apex\Reliability\ConfigProvider as ReliabilityConfigProvider;
use Hyperf\Apex\WebSocket\ConfigProvider as WebSocketConfigProvider;
use Hyperf\Apex\WebSocket\Listener\WsDeclarationCheckListener;
use Hyperf\Apex\WebSocket\Listener\WsGatewayRegisterListener;
use Hyperf\Apex\WebSocket\Listener\WsTableInitListener;
use PHPUnit\Framework\TestCase;

final class ConfigProviderTest extends TestCase
{
    public function testCoreConfigProvider(): void
    {
        $provider = new CoreConfigProvider();
        $config = $provider();

        $this->assertIsArray($config);
        $this->assertArrayHasKey('dependencies', $config);
        $this->assertContains(SchemaModelCheckCommand::class, $config['commands'] ?? []);
        $this->assertContains(DbQueryExecutedListener::class, $config['listeners'] ?? []);
        $this->assertContains(EnvironmentConfigCheckListener::class, $config['listeners'] ?? []);
        $this->assertContains(ResumeExitCoordinatorListener::class, $config['listeners'] ?? []);

        foreach ($config['publish'] ?? [] as $item) {
            $this->assertDirectoryExists($item['source']);
        }
    }

    public function testApiDocConfigProvider(): void
    {
        $provider = new ApiDocConfigProvider();
        $config = $provider();

        $this->assertIsArray($config);
        $this->assertContains(GenerateApiDocCommand::class, $config['commands'] ?? []);
        // Apifox 导出命令不在 apidoc：它要读长连接契约，随 hyperf-apex/apidoc-apifox 分发。
        $this->assertNotContains(GenerateApifoxDocCommand::class, $config['commands'] ?? []);

        foreach ($config['publish'] ?? [] as $item) {
            $this->assertDirectoryExists($item['source']);
        }
    }

    public function testApiDocApifoxConfigProvider(): void
    {
        $provider = new ApiDocApifoxConfigProvider();
        $config = $provider();

        $this->assertIsArray($config);
        $this->assertContains(GenerateApifoxDocCommand::class, $config['commands'] ?? []);

        foreach ($config['publish'] ?? [] as $item) {
            $this->assertDirectoryExists($item['source']);
        }
    }

    public function testWebSocketConfigProvider(): void
    {
        $provider = new WebSocketConfigProvider();
        $config = $provider();

        $this->assertIsArray($config);
        $this->assertContains(WsTableInitListener::class, $config['listeners'] ?? []);
        $this->assertContains(WsGatewayRegisterListener::class, $config['listeners'] ?? []);
        $this->assertContains(WsDeclarationCheckListener::class, $config['listeners'] ?? []);

        foreach ($config['publish'] ?? [] as $item) {
            $this->assertDirectoryExists($item['source']);
        }
    }

    public function testReliabilityConfigProvider(): void
    {
        $provider = new ReliabilityConfigProvider();
        $config = $provider();

        $this->assertIsArray($config);
        $this->assertArrayHasKey('dependencies', $config);

        foreach ($config['publish'] ?? [] as $item) {
            $this->assertDirectoryExists($item['source']);
        }
    }

    public function testMetricsConfigProvider(): void
    {
        $provider = new MetricsConfigProvider();
        $config = $provider();

        $this->assertIsArray($config);
        $this->assertContains(MetricTableInitListener::class, $config['listeners'] ?? []);

        foreach ($config['publish'] ?? [] as $item) {
            $this->assertDirectoryExists($item['source']);
        }
    }
}
