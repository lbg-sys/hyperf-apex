<?php

declare(strict_types=1);

namespace Hyperf\Apex\WeCom\Cache;

use Hyperf\Contract\ConfigInterface;

/**
 * 组件在应用缓存里的键前缀。
 *
 * 单列一处是为了让 access_token 与 OAuth state 用同一个前缀：前缀是「与其它项目共用 Redis 时
 * 的隔离手段」，两个键族用了不同的前缀就等于隔离没做全。默认值与配置模板保持一致。
 */
final class CachePrefix
{
    /** 未配置时的默认前缀，与 publish/config/wecom.php 的默认值一致。 */
    public const DEFAULT = 'wecom:';

    public static function fromConfig(ConfigInterface $config): string
    {
        $prefix = trim((string) $config->get('wecom.cache_prefix', ''));

        return $prefix === '' ? self::DEFAULT : $prefix;
    }
}
