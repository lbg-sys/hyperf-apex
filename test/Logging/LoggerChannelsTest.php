<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Logging;

use Hyperf\Apex\Logging\LoggerChannels;
use Hyperf\Apex\Test\Support\StubLoggerFactory;
use Hyperf\Logger\LoggerFactory;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * 日志通道解析的契约。
 *
 * `LoggerFactory::get($name, $channel)` 在通道未定义时直接抛异常，而组件的取通道都在构造函数里 ——
 * 这一层就是那道防线：配了专用通道就用它，没配就退回 default，绝不因为「应用没配某个通道」而崩。
 * 断言方式是读 LoggerFactory 内部的 logger 缓存键：它精确记录了实际落在了哪个通道上。
 */
final class LoggerChannelsTest extends TestCase
{
    public function testUsesConfiguredChannelAsIs(): void
    {
        $factory = StubLoggerFactory::create('error', ['error' => [], 'shared' => []]);

        LoggerChannels::resolve($factory, 'rate_limit', 'shared');

        $loggers = $this->loggers($factory);
        $this->assertArrayHasKey('shared', $loggers);
        // 没有回退：default 指向的通道不该被碰过。
        $this->assertArrayNotHasKey('error', $loggers);
    }

    public function testFallsBackToDefaultChannelWhenChannelIsMissing(): void
    {
        // 应用没配 shared 通道：不能抛异常，退回 default 继续跑（日志混在一起，但功能正常）。
        $factory = StubLoggerFactory::create('error', ['error' => []]);

        LoggerChannels::resolve($factory, 'rate_limit', 'shared');

        $loggers = $this->loggers($factory);
        $this->assertArrayHasKey('error', $loggers);
        $this->assertArrayNotHasKey('shared', $loggers);
    }

    public function testEmptyChannelUsesDefaultDirectly(): void
    {
        $factory = StubLoggerFactory::create('error', ['error' => []]);

        LoggerChannels::resolve($factory, 'sql', '');

        $loggers = $this->loggers($factory);
        $this->assertArrayHasKey('error', $loggers);
    }

    /**
     * @return array<string, mixed>
     */
    private function loggers(LoggerFactory $factory): array
    {
        $loggers = (new ReflectionProperty(LoggerFactory::class, 'loggers'))->getValue($factory);

        return is_array($loggers) ? $loggers : [];
    }
}
