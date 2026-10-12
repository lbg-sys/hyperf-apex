<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\DTO\Attribute\ArrayOf;
use Hyperf\Apex\DTO\Attribute\Length;
use Hyperf\Apex\DTO\Attribute\MapOf;
use Hyperf\Apex\DTO\Metadata\DTORuleFactory;
use Hyperf\Apex\Validation\Rule\BooleanValueRule;
use Hyperf\Apex\Validation\Rule\NotBooleanRule;
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

    public function testSelfReferencingArrayStopsExpanding(): void
    {
        // 自引用的 DTO（树的 children 是自身）此前会让 itemRules 的 key 每层变长、永不收敛，
        // 首个请求就栈溢出或把内存撑爆。现在「同一个类又出现在当前展开路径上」即停。
        $rules = DTORuleFactory::from(SelfReferencingNodeDTO::class);

        $this->assertArrayHasKey('children.*', $rules);
        $this->assertSame(['array'], $rules['children.*']);
        // 停在第一层：不会出现 children.*.children.*，更不会无限往下。
        $this->assertArrayNotHasKey('children.*.children.*', $rules);
    }

    public function testMapOfValuesAreValidatedLikeArrayOf(): void
    {
        // #[MapOf('int')] 的键是字符串、值是 int，`字段.*` 通配同样成立 ——
        // 此前只有 #[ArrayOf] 会生成元素规则，映射值一路不校验（文档写了 int、服务端照单全收）。
        $rules = DTORuleFactory::from(MapValueRulesDTO::class);

        $this->assertArrayHasKey('scores.*', $rules);
        $this->assertSame(['integer', NotBooleanRule::class], array_map(
            static fn (object|string $rule): string => is_object($rule) ? $rule::class : $rule,
            $rules['scores.*'],
        ));
    }

    public function testMixedPropertyIsNotForcedToBeAnArray(): void
    {
        // mixed 此前落到 default 的 ['array']：文档说「无约束」、水合原样透传、校验却要求数组，
        // 三层结论互相打架 —— 响应与请求复用同一个 DTO 时必然误报 422。
        $rules = DTORuleFactory::from(MixedRulesDTO::class);

        $this->assertNotContains('array', $rules['payload']);
    }
}

/** 自引用：树的 children 是自身。 */
final readonly class SelfReferencingNodeDTO
{
    public function __construct(
        public string $name = '',
        #[ArrayOf(SelfReferencingNodeDTO::class)]
        public array $children = [],
    ) {
    }
}

/** 映射的值类型由 #[MapOf] 声明。 */
final readonly class MapValueRulesDTO
{
    /** @param array<string, int> $scores */
    public function __construct(
        #[MapOf('int')]
        public array $scores = [],
    ) {
    }
}

/** 无约束属性：mixed 不该被要求成数组。 */
final readonly class MixedRulesDTO
{
    public function __construct(
        public mixed $payload = null,
    ) {
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
