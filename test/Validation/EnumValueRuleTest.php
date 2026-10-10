<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Validation;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Notify\Enum\NotifySeverity;
use Hyperf\Apex\Validation\Rule\EnumValueRule;
use PHPUnit\Framework\TestCase;

/**
 * 枚举合法性校验规则。
 *
 * 它存在的原因：Hyperf 自带的 Rule::enum 在 strict_types 下对 int-backed 枚举收到
 * 数字字符串（GET query 永远是字符串）会抛 TypeError 且被判为失败；这里复用
 * EnumInterface::transform() 的归一能力，数字字符串也能通过。
 */
final class EnumValueRuleTest extends TestCase
{
    public function testEnumInstancePasses(): void
    {
        $this->assertTrue((new EnumValueRule(ErrorCode::class))->passes('code', ErrorCode::SUCCESS));
    }

    public function testNumericStringPassesForIntBackedEnum(): void
    {
        // GET query 传来的数字字符串必须能通过，否则 int 枚举筛选条件全部中招。
        $this->assertTrue((new EnumValueRule(ErrorCode::class))->passes('code', '0'));
    }

    public function testStringValuePassesForStringBackedEnum(): void
    {
        $this->assertTrue((new EnumValueRule(NotifySeverity::class))->passes('severity', 'info'));
    }

    public function testBoolFloatNullAndArrayAreRejected(): void
    {
        $rule = new EnumValueRule(ErrorCode::class);

        $this->assertFalse($rule->passes('code', true));
        $this->assertFalse($rule->passes('code', 1.5));
        $this->assertFalse($rule->passes('code', null));
        $this->assertFalse($rule->passes('code', [0]));
    }

    public function testUnknownValueIsRejected(): void
    {
        $this->assertFalse((new EnumValueRule(ErrorCode::class))->passes('code', '999999'));
    }
}
