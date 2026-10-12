<?php

declare(strict_types=1);

namespace Hyperf\Apex\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * 时间工具：当前时间与时间解析的唯一入口 + 服务端出站时间格式化。
 *
 * - 当前时间统一走 `now()`，取应用服务器本地时间（由应用的 `date_default_timezone` 决定），
 *   不依赖数据库时钟；各机器时钟经 NTP 同步后，应用侧写入的时间与库内数据、与查询比较
 *   条件都是同一口径。
 * - 时间解析统一走 `parse()`，调用点不直接依赖 Carbon 的静态 API，时区口径也只在这里定义。
 * - 出站格式化：响应里的时间字段统一输出 'Y-m-d H:i:s'。
 *   模型时间属性的运行时类型不稳定（Carbon 或 string），这里统一收口，避免各 DTO 重复实现。
 */
final class Time
{
    /**
     * 当前时间。
     *
     * 返回不可变对象，addSeconds() / subDays() 这类偏移计算不会污染其它调用点；落库或参与比较时
     * 用 `toDateTimeString()` 取 'Y-m-d H:i:s'。测试可用 `CarbonImmutable::setTestNow()` 固定。
     */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    /**
     * 把时间值解析成不可变时间对象。
     *
     * 库里读出来的时间列是 'Y-m-d H:i:s' 字符串，参与比较或做偏移计算前先过这里。
     * 一律按应用配置的本地时区解释，与 `now()` / `toDateTimeString()` 同一口径；
     * 时区口径只由应用的 `date_default_timezone` 决定，所以这里不开放时区参数。
     */
    public static function parse(DateTimeInterface|float|int|string|null $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, date_default_timezone_get());
    }

    /**
     * 有值的时间转 'Y-m-d H:i:s'，null 保持 null。
     *
     * 用于可空时间字段：区分「没有值」和「有值为空」，不做兜底转换。
     */
    public static function toNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return method_exists($value, 'toDateTimeString') ? $value->toDateTimeString() : (string) $value;
    }

    /**
     * 有值的时间转 'Y-m-d H:i:s'，null 归一为空串。
     *
     * 仅用于类型系统上不可空的时间字段（DB 列为 NOT NULL），语义是「没有值」按空串输出。
     */
    public static function toString(mixed $value): string
    {
        return self::toNullableString($value) ?? '';
    }
}
