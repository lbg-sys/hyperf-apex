<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\RateLimit;

use Hyperf\Apex\RateLimit\RateLimitRule;
use Hyperf\Apex\RateLimit\RateLimitScopes;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RateLimitRuleTest extends TestCase
{
    public function testNonPositiveNumbersAreRejectedAtConstruction(): void
    {
        $cases = [
            [0, 1, 1, 'capacity'],
            [60, 0, 1, 'refill'],
            [60, 1, 0, 'consume'],
        ];

        foreach ($cases as [$capacity, $refill, $consume, $field]) {
            try {
                new RateLimitRule('login-ip', RateLimitScopes::IP, $capacity, $refill, $consume);
                $this->fail(sprintf('%s 为 0 时应当直接报错，而不是被静默钳制成 1。', $field));
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    sprintf('限流规则 login-ip 的 %s 必须是正整数', $field),
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testDeclaredNumbersSurviveConstruction(): void
    {
        $rule = new RateLimitRule('login-ip', RateLimitScopes::IP, 60, 2, 5);

        $this->assertSame(60, $rule->capacity);
        $this->assertSame(2, $rule->refill);
        $this->assertSame(5, $rule->consume);
    }
}
