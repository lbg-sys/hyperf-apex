<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\DTO\Attribute\Length;
use Hyperf\Apex\DTO\Metadata\DTORuleFactory;
use Hyperf\Apex\Validation\Rule\BooleanValueRule;
use PHPUnit\Framework\TestCase;

final class DTORuleFactoryTest extends TestCase
{
    public function testRulesAreCachedButObjectRulesAreNotShared(): void
    {
        $first = DTORuleFactory::from(RulesDTO::class);
        $second = DTORuleFactory::from(RulesDTO::class);

        $this->assertSame(['name', 'enabled'], array_keys($first));

        // 字符串规则按值语义，两次取到的内容一致即可。
        $this->assertSame(['sometimes', 'string', 'min:1', 'max:32'], $first['name']);
        $this->assertSame($first['name'], $second['name']);

        // bool 字段除必填判定外只有一条对象规则。
        $this->assertSame(['sometimes', BooleanValueRule::class], array_map(
            static fn (object|string $rule): string => is_object($rule) ? $rule::class : $rule,
            $first['enabled'],
        ));

        // 对象规则必须每次克隆：ValidatorAwareRule 会在校验时写自身状态，
        // 跨协程共享同一个实例就是两个请求互相覆写。
        $this->assertNotSame($first['enabled'][1], $second['enabled'][1]);
    }
}

/** 覆盖「字符串规则 + 对象规则」两种形态。 */
final readonly class RulesDTO
{
    public function __construct(
        #[Length(min: 1, max: 32)]
        public string $name = '',
        public bool $enabled = false,
    ) {
    }
}
