<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Support;

use Hyperf\Apex\Support\SensitiveDataFilter;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * 递归脱敏的契约。
 *
 * 这是异常快照、审计 change_data 与 SQL 绑定脱敏**共用**的一份口径 —— 三处若各写一套，
 * 迟早有一处漏掉某个关键字、把敏感值明文落进日志或审计表。
 */
final class SensitiveDataFilterTest extends TestCase
{
    public function testSensitiveKeysAreRedacted(): void
    {
        $this->assertSame(['password' => '[redacted]'], SensitiveDataFilter::filter(['password' => 'x']));
    }

    public function testMatchIsContainsNotExact(): void
    {
        // admin_password / access_token / refreshToken / cookie 都该命中，不用各列一条全等规则。
        $filtered = SensitiveDataFilter::filter([
            'admin_password' => 'x',
            'access_token' => 'y',
            'refreshToken' => 'z',
            'cookie' => 'w',
        ]);

        $this->assertSame([
            'admin_password' => '[redacted]',
            'access_token' => '[redacted]',
            'refreshToken' => '[redacted]',
            'cookie' => '[redacted]',
        ], $filtered);
    }

    public function testNonSensitiveValuesPassThrough(): void
    {
        $this->assertSame(['name' => 'alice', 'age' => 30], SensitiveDataFilter::filter(['name' => 'alice', 'age' => 30]));
    }

    public function testNestedStructureIsRecursed(): void
    {
        $filtered = SensitiveDataFilter::filter(['user' => ['password' => 'x', 'email' => 'a@b.c']]);

        $this->assertSame(['user' => ['password' => '[redacted]', 'email' => 'a@b.c']], $filtered);
    }

    public function testDepthIsLimited(): void
    {
        $value = 'leaf';
        for ($i = 0; $i < 10; ++$i) {
            $value = ['n' => $value];
        }

        $this->assertStringContainsString('[depth limited]', json_encode(SensitiveDataFilter::filter($value), JSON_THROW_ON_ERROR));
    }

    public function testNonScalarNonArrayIsReplacedByItsType(): void
    {
        $this->assertSame('[stdClass]', SensitiveDataFilter::filter(new stdClass()));
    }

    public function testKeyMatchIsCaseInsensitive(): void
    {
        $this->assertTrue(SensitiveDataFilter::isSensitiveKey('Password'));
        $this->assertTrue(SensitiveDataFilter::isSensitiveKey('X-Api-Secret'));
        $this->assertFalse(SensitiveDataFilter::isSensitiveKey('email'));
    }
}
