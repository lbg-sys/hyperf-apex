<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\RateLimit;

use Hyperf\Apex\RateLimit\Attribute\RateLimit;
use Hyperf\Apex\RateLimit\RateLimitRuleReader;
use Hyperf\Apex\RateLimit\RateLimitScopes;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class RateLimitRuleReaderTest extends TestCase
{
    public function testMethodLevelRuleWinsAndIsCached(): void
    {
        $reader = new RateLimitRuleReader();
        $method = new ReflectionMethod(RateLimitedEndpoint::class, 'login');

        $first = $reader->read($method);
        $second = $reader->read($method);

        $this->assertCount(1, $first);
        $this->assertSame('login', $first[0]->name);
        $this->assertSame(3, $first[0]->capacity);

        // 命中缓存：规则对象不可变，同一份直接复用。
        $this->assertSame($first, $second);
    }

    public function testFallsBackToClassLevelRule(): void
    {
        $rules = (new RateLimitRuleReader())->read(new ReflectionMethod(RateLimitedEndpoint::class, 'me'));

        $this->assertCount(1, $rules);
        $this->assertSame(10, $rules[0]->capacity);
        // 未显式命名时按「完整类名.方法名」派生，保证每个接口一个桶。
        $this->assertSame(strtolower(str_replace('\\', '.', RateLimitedEndpoint::class) . '.me'), $rules[0]->name);
    }
}

#[RateLimit(capacity: 10, refill: 2, scope: RateLimitScopes::IP)]
final class RateLimitedEndpoint
{
    #[RateLimit(name: 'login', capacity: 3)]
    public function login(): void
    {
    }

    public function me(): void
    {
    }
}
