<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket\Support;

use Hyperf\Apex\WebSocket\Attribute\WsGateway;
use Hyperf\Apex\WebSocket\Attribute\WsRoute;
use Hyperf\Di\Annotation\AnnotationCollector;
use ReflectionProperty;

/**
 * 单测里注册 WebSocket 声明（方法级 `#[WsRoute]` 与类级 `#[WsGateway]`）。
 *
 * 运行期这份元数据由启动扫描填充；单测不启动 server，所以直接写注解收集器的静态容器。
 * 容器是 `protected static`，只能走反射；结构与扫描器填的完全一致：
 * 方法级 `[类][_m][方法][注解类]`、类级 `[类][注解类]`。
 *
 * 用完必须 `clear()`（见各用例的 tearDown）：收集器是进程级静态状态，不清理会串到别的用例。
 */
final class WsAnnotationRegistrar
{
    public static function route(string $class, string $method, string $event): void
    {
        $container = self::container();
        $container[$class]['_m'][$method][WsRoute::class] = new WsRoute($event);

        self::store($container);
    }

    public static function gateway(string $class, WsGateway $annotation): void
    {
        $container = self::container();
        // 类级注解在收集器里是 `[类][_c][注解类]`（方法级是 `[类][_m][方法][注解类]`）。
        $container[$class]['_c'][WsGateway::class] = $annotation;

        self::store($container);
    }

    public static function clear(): void
    {
        AnnotationCollector::clear();
    }

    /**
     * @return array<string, mixed>
     */
    private static function container(): array
    {
        $property = new ReflectionProperty(AnnotationCollector::class, 'container');
        $container = $property->getValue();

        return is_array($container) ? $container : [];
    }

    /**
     * @param array<string, mixed> $container
     */
    private static function store(array $container): void
    {
        (new ReflectionProperty(AnnotationCollector::class, 'container'))->setValue(null, $container);
    }
}
