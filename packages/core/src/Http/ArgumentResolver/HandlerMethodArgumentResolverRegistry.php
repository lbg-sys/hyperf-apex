<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\ArgumentResolver;

use Hyperf\Contract\ConfigInterface;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

final class HandlerMethodArgumentResolverRegistry
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ConfigInterface $config,
    ) {
    }

    /** @return list<HandlerMethodArgumentResolverInterface> */
    public function all(): array
    {
        $resolverClasses = [
            FromBodyArgumentResolver::class,
            FromQueryArgumentResolver::class,
            FromMultipartArgumentResolver::class,
        ];
        $resolvers = [];
        foreach (array_merge($resolverClasses, $this->config->get('argument_resolvers', [])) as $resolver) {
            if (is_string($resolver)) {
                $resolver = $this->container->get($resolver);
            }
            if (! $resolver instanceof HandlerMethodArgumentResolverInterface) {
                throw new InvalidArgumentException(sprintf(
                    '参数解析器必须实现 %s，当前是 %s。',
                    HandlerMethodArgumentResolverInterface::class,
                    is_object($resolver) ? $resolver::class : get_debug_type($resolver)
                ));
            }
            $resolvers[] = $resolver;
        }
        return $resolvers;
    }
}
