<?php

declare(strict_types=1);

namespace Hyperf\Apex\RateLimit;

use Hyperf\Apex\RateLimit\Contract\RateLimitIdentityResolverInterface;
use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * 按维度查找解析器并解析主体标识。
 *
 * 解析器按配置构建而不是自动扫描：显式配置可追溯，也避免把未启用模块的解析器拉进容器。
 */
final class RateLimitIdentityResolverRegistry
{
    /** @var array<string, RateLimitIdentityResolverInterface> */
    private array $resolvers = [];

    public function __construct(ContainerInterface $container, ConfigInterface $config)
    {
        $classes = $config->get('rate_limit.resolvers', []);
        if (! is_array($classes)) {
            throw new RuntimeException('配置 rate_limit.resolvers 必须是解析器类名数组。');
        }

        foreach ($classes as $class) {
            if (! is_string($class) || $class === '') {
                throw new RuntimeException('配置 rate_limit.resolvers 只能包含解析器类名。');
            }

            $resolver = $container->get($class);
            if (! $resolver instanceof RateLimitIdentityResolverInterface) {
                throw new RuntimeException(sprintf('%s 必须实现 %s。', $class, RateLimitIdentityResolverInterface::class));
            }

            $scope = $resolver->scope();
            if (isset($this->resolvers[$scope])) {
                // 同一维度两个解析器会让限流结果取决于注册顺序，属于配置错误，启动期就失败。
                throw new RuntimeException(sprintf(
                    '限流维度 %s 被 %s 与 %s 重复注册。',
                    $scope,
                    $this->resolvers[$scope]::class,
                    $class
                ));
            }

            $this->resolvers[$scope] = $resolver;
        }
    }

    /**
     * 解析标识；维度没有对应解析器时返回 null，由调用方跳过规则。
     */
    public function resolve(string $scope, ServerRequestInterface $request): ?string
    {
        $resolver = $this->resolvers[$scope] ?? null;

        return $resolver?->resolve($request);
    }
}
