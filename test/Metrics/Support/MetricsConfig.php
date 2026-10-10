<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Metrics\Support;

use Hyperf\Config\Config;
use Hyperf\Contract\ConfigInterface;

/**
 * 用**真实的**配置模板造 metrics 配置。
 *
 * 用例要覆盖的是「配置 → 行展开 → 取值」这条链路，自己手写一份精简配置就测不到真实定义了
 * （例如 `@http_routes` 这类动态值域、以及 aggregates 与定义里的标签是否对得上）。
 * 覆盖项按子键浅合并，方便只改某一项。
 */
final class MetricsConfig
{
    /**
     * @param array<string, mixed> $overrides
     */
    public static function from(array $overrides = []): ConfigInterface
    {
        /** @var array<string, mixed> $template */
        $template = require BASE_PATH . '/packages/metrics/publish/metrics.php';

        foreach ($overrides as $key => $value) {
            $template[$key] = is_array($value) && is_array($template[$key] ?? null)
                ? array_merge($template[$key], $value)
                : $value;
        }

        return new Config(['metrics' => $template]);
    }
}
