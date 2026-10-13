<?php

declare(strict_types=1);

namespace Hyperf\Apex\WeCom;

use Hyperf\Apex\WeCom\Cache\AccessTokenStore;
use Hyperf\Apex\WeCom\Config\AccountResolverInterface;
use Hyperf\Apex\WeCom\Config\ConfigAccountResolver;
use Hyperf\Apex\WeCom\Login\OAuthStateStore;
use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;

class ConfigProvider
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                // 默认从 config/autoload/wecom.php 读企业配置；改成数据库或配置中心时替换这一个绑定即可。
                AccountResolverInterface::class => ConfigAccountResolver::class,
                /*
                 * 两个键族（access_token / OAuth state）都只需要「缓存 + 前缀」，
                 * 前缀与 state TTL 来自配置，因此在这里把配置读进来，业务类只依赖这两个对象。
                 */
                AccessTokenStore::class => static fn (ContainerInterface $container): AccessTokenStore => AccessTokenStore::fromConfig(
                    $container->get(CacheInterface::class),
                    $container->get(ConfigInterface::class),
                ),
                OAuthStateStore::class => static fn (ContainerInterface $container): OAuthStateStore => OAuthStateStore::fromConfig(
                    $container->get(CacheInterface::class),
                    $container->get(ConfigInterface::class),
                ),
            ],
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'apex 企业微信组件的配置模板。',
                    'source' => __DIR__ . '/../publish/config/',
                    'destination' => BASE_PATH . '/config/autoload/',
                ],
            ],
        ];
    }
}
