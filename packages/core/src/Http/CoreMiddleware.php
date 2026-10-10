<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http;

use Hyperf\Apex\Http\ArgumentResolver\HandlerMethodArgumentResolverInterface;
use Hyperf\Apex\Http\ArgumentResolver\HandlerMethodArgumentResolverRegistry;
use Hyperf\Apex\Http\Response\ResponseTransformerRegistry;
use Hyperf\Context\RequestContext;
use Hyperf\Contract\Arrayable;
use Hyperf\Contract\Htmlable;
use Hyperf\Contract\Jsonable;
use Hyperf\Di\ReflectionManager;
use Hyperf\HttpServer\CoreMiddleware as HyperfCoreMiddleware;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swow\Psr7\Message\ResponsePlusInterface;

class CoreMiddleware extends HyperfCoreMiddleware
{
    /** @var list<HandlerMethodArgumentResolverInterface> */
    private array $argumentResolvers;

    public function __construct(
        ContainerInterface $container,
        string $serverName,
        HandlerMethodArgumentResolverRegistry $registry,
        private readonly ResponseTransformerRegistry $responseTransformers,
        private readonly HandlerMethodResolver $handlerMethods,
    ) {
        parent::__construct($container, $serverName);
        $this->argumentResolvers = $registry->all();
    }

    /** @param null|array<array-key, mixed>|Arrayable<array-key, mixed>|Htmlable|Jsonable|ResponseInterface|string $response */
    protected function transferToResponse($response, ServerRequestInterface $request): ResponsePlusInterface
    {
        $response = $this->responseTransformers->transform($response, $this->handlerMethods->resolve($request), $request);

        return parent::transferToResponse($response, $request);
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return list<mixed>
     */
    protected function parseMethodParameters(string $controller, string $action, array $arguments): array
    {
        $definitions = $this->getMethodDefinitionCollector()->getParameters($controller, $action);
        $parameters = ReflectionManager::reflectMethod($controller, $action)->getParameters();
        $request = RequestContext::get();
        $injections = [];

        foreach ($definitions as $position => $definition) {
            $parameter = $parameters[$position];
            $resolved = false;
            foreach ($this->argumentResolvers as $resolver) {
                if (! $resolver->supportsParameter($parameter)) {
                    continue;
                }
                $injections[] = $resolver->resolveArgument($parameter, $request, $arguments);
                $resolved = true;
                break;
            }
            if ($resolved) {
                continue;
            }

            $value = $arguments[$position] ?? $arguments[$definition->getMeta('name')] ?? null;
            if ($value !== null) {
                $injections[] = $this->getNormalizer()->denormalize($value, $definition->getName());
                continue;
            }

            if ($definition->getMeta('defaultValueAvailable')) {
                $injections[] = $definition->getMeta('defaultValue');
            } elseif ($this->container->has($definition->getName())) {
                $injections[] = $this->container->get($definition->getName());
            } elseif ($definition->allowsNull()) {
                $injections[] = null;
            } else {
                throw new InvalidArgumentException(sprintf(
                    "Parameter '%s' of %s::%s should not be null",
                    $definition->getMeta('name'),
                    $controller,
                    $action
                ));
            }
        }

        return $injections;
    }
}
