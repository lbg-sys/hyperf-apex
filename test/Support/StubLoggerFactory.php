<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Support;

use Hyperf\Config\Config;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ContainerInterface;
use Hyperf\Logger\LoggerFactory;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\NullHandler;
use Psr\Log\NullLogger;

/**
 * 单测用的 LoggerFactory。
 *
 * 仓库里的服务普遍注入 `LoggerFactory` 再 `->get($name, $channel)`，而它的 `make()` 走的是
 * 全局容器（`Hyperf\Support\make()`）。单测不启动 server，于是这里用两个替身把链路接上：
 *
 * - 一个吞掉一切的容器（`has()` 一律 false —— AbstractModel 会通过容器找事件调度器，
 *   返回 true 会让模型构造拿到一个不是调度器的对象）；
 * - formatter / handler / logger 三类对象都有声明的返回类型，按名字返回对应的空实现。
 *
 * 日志在多数用例里只是旁路，断言应该落在真实的副作用（数据库状态、指标值）上。
 */
final class StubLoggerFactory
{
    public static function create(string $channel = 'error'): LoggerFactory
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                return new NullLogger();
            }

            public function has(string $id): bool
            {
                return false;
            }

            public function make(string $name, array $parameters = []): mixed
            {
                return match (true) {
                    str_contains($name, 'Formatter') => new LineFormatter(),
                    str_contains($name, 'Handler') => new NullHandler(),
                    default => new NullLogger(),
                };
            }

            public function set(string $name, mixed $entry): void
            {
            }

            public function unbind(string $name): void
            {
            }

            public function define(string $name, mixed $definition): void
            {
            }
        };

        ApplicationContext::setContainer($container);

        return new LoggerFactory($container, new Config([
            'logger' => [
                'default' => $channel,
                'channels' => [$channel => []],
            ],
        ]));
    }
}
