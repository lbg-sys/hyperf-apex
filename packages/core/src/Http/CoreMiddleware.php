<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http;

use Hyperf\Apex\DTO\AbstractDTO;
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
use LogicException;
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

            /*
             * 请求 DTO 没有显式声明来源时必须立刻报错，不能落到下面的容器注入。
             *
             * Hyperf 的容器对任何可实例化的类都能建出定义（DefinitionSource::getDefinition()
             * 会对未知类自动 autowire），于是 `container->get(StoreRequestDTO::class)` 会**成功**
             * 地构造一个空 DTO —— 请求体被静默忽略，控制器拿到一份空数据；若 DTO 有必填构造参数，
             * 则变成一条看不懂的容器异常。两种情况都不提示「缺了 #[FromBody]」。
             */
            $typeName = $definition->getName();
            if (is_a($typeName, AbstractDTO::class, true)) {
                throw new LogicException(sprintf(
                    '%s::%s 的参数 $%s 是请求 DTO，必须用 #[FromQuery] / #[FromBody] / #[FromMultipart] 指明取值来源。',
                    $controller,
                    $action,
                    $definition->getMeta('name'),
                ));
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
