<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket;

use Hyperf\Apex\WebSocket\ConfigProvider;
use PHPUnit\Framework\TestCase;

/**
 * 配置默认值的唯一性守卫。
 *
 * 默认值现在只有一处：包内 ConfigProvider（框架按 array_merge_recursive 合并，config/autoload 覆盖它）。
 * publish/websocket.php 是「发布给应用的副本」，它必须带着同一套默认值 ——
 * 否则「没发布配置」与「发布了配置」会跑出两套行为，而差异只会在某个深夜的线上才被发现
 * （idle_timeout 一度在三个地方分别是 180 / 120 / 60）。
 */
final class WsConfigDefaultsTest extends TestCase
{
    public function testPublishTemplateCarriesTheSameDefaultsAsTheConfigProvider(): void
    {
        $this->skipWhenEnvironmentOverridesTheDefaults();

        $provider = new ConfigProvider();
        $response = $provider();
        /** @var array<string, mixed> $defaults */
        $defaults = $response['websocket'];

        /** @var array<string, mixed> $template */
        $template = require BASE_PATH . '/packages/websocket/publish/websocket.php';

        $this->assertNotSame([], $defaults, 'ConfigProvider 必须下发 websocket 默认值。');

        foreach ($defaults as $key => $value) {
            $this->assertArrayHasKey($key, $template, sprintf('publish/websocket.php 缺少 websocket.%s。', $key));
            $this->assertSame(
                $value,
                $template[$key],
                sprintf('websocket.%s 在 ConfigProvider 与 publish 模板里的默认值不一致。', $key),
            );
        }
    }

    /**
     * 模板里的值来自 env()：环境变量一旦被设上，读到的就不是默认值，比较也就没有意义。
     */
    private function skipWhenEnvironmentOverridesTheDefaults(): void
    {
        $environment = getenv();
        if (! is_array($environment)) {
            return;
        }

        foreach (array_keys($environment) as $name) {
            if (str_starts_with((string) $name, 'WS_')) {
                $this->markTestSkipped(sprintf('环境里存在 %s，模板返回的不再是默认值。', $name));
            }
        }
    }
}
