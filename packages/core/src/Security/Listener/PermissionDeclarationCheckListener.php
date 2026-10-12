<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security\Listener;

use Hyperf\Apex\Security\AccessDeclarationReader;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\AnnotationCollector;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BootApplication;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Mapping;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * 启动期校验每个接口端点都显式声明了访问口径。
 *
 * 组件不实现鉴权（那属于应用），但「有没有声明」这件事必须有人守：一个漏标口径的方法
 * 既不会被任何运行时检查拦下，文档侧又会把它描述成公开接口（`security` 被省略）——
 * 方向恰好是最危险的那个，且没有任何迹象。
 *
 * 覆盖范围是「带路由注解的公开方法」，因此继承来的方法、trait 里的方法都算在内；
 * 类级声明由 {@see AccessDeclarationReader} 沿继承链查找。校验项两类：
 * 完全没声明、同一处声明了多个互斥的口径。
 *
 * 与 `WsDeclarationCheckListener` 同样的时机与风格：契约错误在启动期一次性汇总抛出，
 * 而不是留到客户端请求时。
 */
final class PermissionDeclarationCheckListener implements ListenerInterface
{
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly AccessDeclarationReader $declarations,
    ) {
    }

    public function listen(): array
    {
        return [BootApplication::class];
    }

    public function process(object $event): void
    {
        if (! (bool) $this->config->get('security.declaration_check.enabled', true)) {
            return;
        }

        $errors = [];
        foreach (array_keys(AnnotationCollector::getClassesByAnnotation(Controller::class)) as $class) {
            foreach ($this->endpoints($class) as $method) {
                array_push($errors, ...$this->issues($method));
            }
        }

        if ($errors !== []) {
            throw new RuntimeException("接口访问声明检查失败：\n - " . implode("\n - ", $errors));
        }
    }

    /**
     * 控制器上带路由注解的公开方法。
     *
     * 用反射而不是 `AnnotationCollector::getMethodsByAnnotation()`：后者按注解类名精确匹配，
     * 拿不到 `#[GetMapping]` 这类继承自 `Mapping` 的写法。
     *
     * @param class-string $class
     * @return list<ReflectionMethod>
     */
    private function endpoints(string $class): array
    {
        if (! class_exists($class)) {
            return [];
        }

        $methods = [];
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->isAbstract()) {
                continue;
            }

            if ($method->getAttributes(Mapping::class, ReflectionAttribute::IS_INSTANCEOF) === []) {
                continue;
            }

            $methods[] = $method;
        }

        return $methods;
    }

    /**
     * @return list<string>
     */
    private function issues(ReflectionMethod $method): array
    {
        $where = $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()';
        $declaration = $this->declarations->read($method);

        if (! $declaration->isDeclared()) {
            return [sprintf(
                '%s 没有声明访问口径：请标注 #[PublicAccess] / #[IntegrationAccess] / #[ApiKeyAccess] '
                . '/ #[Authenticated] 之一，或在该类上声明。',
                $where
            )];
        }

        $modes = $declaration->declaredAccessModes();
        if (count($modes) > 1) {
            return [sprintf(
                '%s 同时声明了多个访问口径（%s）：口径只能有一个，声明了两个时「到底怎么进来」没有唯一答案。',
                $where,
                implode(' + ', $modes)
            )];
        }

        return [];
    }
}
