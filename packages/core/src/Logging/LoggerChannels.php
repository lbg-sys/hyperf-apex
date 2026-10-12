<?php

declare(strict_types=1);

namespace Hyperf\Apex\Logging;

use Hyperf\Logger\Exception\InvalidConfigException;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * 日志通道解析：组件一律从这里取 logger，不要直接调 `LoggerFactory::get($name, $channel)`。
 *
 * 组件按用途分流日志（异常走 `error`、限流走 `shared`、出站走 `outbound`、SQL 走 `sql`、
 * 访问日志走 `access`），但这些通道名是组件自己约定的，Hyperf 骨架默认只定义 `default`。
 * 而 `LoggerFactory::get()` 在通道不存在时会**直接抛 InvalidConfigException**，调用点又都在
 * 构造函数里 —— 应用没配通道时，第一个用到该组件的请求（或启动期的监听器）就会失败，
 * 报的还是「Logger config[shared] is not defined」，与真正的现象离得很远。
 *
 * 所以统一从这里取：配了专用通道就用它（日志按用途分流），没配就退回 `default`
 * （日志混在一起，但功能不受影响）。README 的「日志通道」一节列出了推荐配置的通道名。
 */
final class LoggerChannels
{
    /**
     * @param string $name logger 名（出现在日志行的前缀里）
     * @param string $channel 通道名，配置缺失时退回到 default 通道
     */
    public static function resolve(LoggerFactory $factory, string $name, string $channel): LoggerInterface
    {
        if ($channel === '') {
            return $factory->get($name);
        }

        try {
            return $factory->get($name, $channel);
        } catch (InvalidConfigException) {
            return $factory->get($name);
        }
    }
}
