<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket\Support;

use Hyperf\Apex\WebSocket\ConfigProvider;
use Hyperf\Config\Config;
use Hyperf\Contract\ConfigInterface;

/**
 * 用例用的配置对象：以包内 ConfigProvider 下发的默认值为底，叠加用例自己的覆盖项。
 *
 * 生产环境的默认值只存在于 ConfigProvider（由框架按 array_merge_recursive 合并，
 * config/autoload 覆盖它）。用例若直接 `new Config(['websocket' => [...]])`，就等于绕开了这份默认值，
 * 于是「用例没写某一项」变成「该项为 0/空」—— 与线上行为完全不同。这里强制所有用例从同一份默认值出发。
 */
final class WsTestConfig
{
    /**
     * @param array<string, mixed> $websocket websocket.* 的覆盖项
     * @param array<string, mixed> $extra 其它一级配置键（如 server、integration）
     */
    public static function make(array $websocket = [], array $extra = []): ConfigInterface
    {
        $provider = new ConfigProvider();
        $response = $provider();
        /** @var array<string, mixed> $defaults */
        $defaults = $response['websocket'] ?? [];

        return new Config(array_replace_recursive($extra, [
            'websocket' => array_replace_recursive($defaults, $websocket),
        ]));
    }
}
