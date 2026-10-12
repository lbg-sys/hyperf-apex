<?php

declare(strict_types=1);

namespace Hyperf\Apex\RateLimit;

use Hyperf\Apex\RateLimit\Attribute\RateLimit;
use ReflectionAttribute;
use ReflectionMethod;

/**
 * 读取目标上的限流规则：方法级优先，方法未声明时回退到类级。
 *
 * 同一目标可以叠加多条规则（注解可重复），多条之间是「全部通过才放行」的关系。
 */
final class RateLimitRuleReader
{
    /** @var array<string, list<RateLimitRule>> */
    private static array $cache = [];

    /**
     * @return list<RateLimitRule>
     */
    public function read(ReflectionMethod $method): array
    {
        // 规则只由注解位置决定：缓存按「声明类::方法」建键，类级回退也只依赖声明类。
        // 限流中间件每个请求都要读一次规则，而 RateLimitRule 是不可变对象，可以安全共享。
        $key = $method->getDeclaringClass()->getName() . '::' . $method->getName();
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $rules = $this->collect($method->getAttributes(RateLimit::class), $method);
        if ($rules === []) {
            $rules = $this->collect($method->getDeclaringClass()->getAttributes(RateLimit::class), $method);
        }

        return self::$cache[$key] = $rules;
    }

    /**
     * @param list<ReflectionAttribute<RateLimit>> $attributes
     * @return list<RateLimitRule>
     */
    private function collect(array $attributes, ReflectionMethod $method): array
    {
        $rules = [];
        foreach ($attributes as $attribute) {
            /** @var RateLimit $declared */
            $declared = $attribute->newInstance();
            $rules[] = new RateLimitRule(
                $declared->name !== '' ? $declared->name : $this->fallbackName($method),
                $declared->scope,
                $declared->capacity,
                $declared->refill,
                $declared->consume,
                $declared->message,
            );
        }

        return $rules;
    }

    /**
     * 未显式命名时按「完整类名.方法名」派生。
     *
     * 只取短类名会让不同命名空间下的同名控制器共用一个桶；
     * 带上完整类名才能保证每个接口一个桶。
     */
    private function fallbackName(ReflectionMethod $method): string
    {
        return strtolower(str_replace('\\', '.', $method->getDeclaringClass()->getName()) . '.' . $method->getName());
    }
}
