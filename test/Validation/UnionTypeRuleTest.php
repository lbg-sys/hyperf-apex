<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Validation;

use Hyperf\Apex\DTO\Attribute\MapOf;
use Hyperf\Apex\DTO\Metadata\DTORuleFactory;
use Hyperf\Apex\Validation\Rule\UnionTypeRule;
use PHPUnit\Framework\TestCase;

/**
 * 联合类型（`int|string`）的校验口径。
 *
 * 需要它的原因：联合类型没有单一类型名可以表达，此前整段不加类型规则 —— 类型不符的值
 * 能通过校验，直到构造器赋值时才抛 TypeError，客户端拿到 500 而不是 422；而文档侧本来
 * 就用 oneOf 描述这些字段。
 */
final class UnionTypeRuleTest extends TestCase
{
    public function testItAcceptsAnyBranch(): void
    {
        $rule = new UnionTypeRule(['int', 'string']);

        $this->assertTrue($rule->passes('value', 12));
        // 数字字符串走 int 分支（与单类型字段的 integer 规则同口径）。
        $this->assertTrue($rule->passes('value', '12'));
        $this->assertTrue($rule->passes('value', 'abc'));
    }

    public function testItRejectsValuesMatchingNoBranch(): void
    {
        $rule = new UnionTypeRule(['int', 'string']);

        $this->assertFalse($rule->passes('value', []));
        $this->assertFalse($rule->passes('value', ['a' => 1]));
        // 布尔不算整数：filter_var 会把 true 变成 "1"，必须先排除（与 NotBooleanRule 同口径）。
        $this->assertFalse($rule->passes('value', true));
        $this->assertFalse($rule->passes('value', null));
    }

    public function testBooleanBranchReusesTheSingleTypeRule(): void
    {
        // `bool` 分支复用 BooleanValueRule::accepts()：两侧口径不一致时，同一个输入会在
        // 「单类型 bool 字段」与「bool 在联合里」两个字段上得到相反结论。
        $rule = new UnionTypeRule(['bool', 'array']);

        $this->assertTrue($rule->passes('flag', 'true'));
        $this->assertTrue($rule->passes('flag', 1));
        $this->assertTrue($rule->passes('flag', []));
        $this->assertFalse($rule->passes('flag', '不是布尔'));
    }

    public function testMessageListsTheAllowedTypes(): void
    {
        $this->assertSame(
            ':attribute 的类型必须是 int / string 之一。',
            (new UnionTypeRule(['int', 'string']))->message(),
        );
    }

    public function testFactoryAttachesTheRuleForUnionTypedProperties(): void
    {
        $rules = DTORuleFactory::from(UnionTypeRulesDTO::class);

        $this->assertSame(['sometimes', UnionTypeRule::class], self::names($rules['value']));
        // 可空联合：null 分支由 nullable 规则承担，不重复表达在 UnionTypeRule 里。
        $this->assertSame(['sometimes', UnionTypeRule::class, 'nullable'], self::names($rules['maybe']));
        // 有分支本身没有类型约束（object）时整个联合没有可判定的边界，退回不加规则。
        $this->assertSame(['sometimes'], self::names($rules['loose']));
        // 规则对象与其它规则一样按请求克隆：ValidatorAwareRule 会写自身状态。
        $this->assertNotSame($rules['value'][1], DTORuleFactory::from(UnionTypeRulesDTO::class)['value'][1]);
    }

    public function testUnionSyntaxInsideATypeExpressionYieldsNoRule(): void
    {
        // 类型表达式（#[ArrayOf('...')] / #[MapOf('...')]）不接受联合语法：校验层退回不加约束
        // （itemRules 在请求热路径上，认不出的写法不能抛异常变成 500），
        // 而文档层会在生成期直接报「内层类型无法解析」—— 所以这种写法进不了产物，
        // 不会真的留下「文档与校验对不上」的接口。
        $rules = DTORuleFactory::from(UnionTypeRulesDTO::class);

        $this->assertSame([], $rules['scores.*']);
    }

    /**
     * @param list<object|string> $rules
     * @return list<string>
     */
    private static function names(array $rules): array
    {
        return array_map(
            static fn (object|string $rule): string => is_object($rule) ? $rule::class : $rule,
            $rules,
        );
    }
}

/** 覆盖「纯联合 / 可空联合 / 含无约束分支的联合」三种形态。 */
final readonly class UnionTypeRulesDTO
{
    public function __construct(
        public int|string $value = '',
        public int|string|null $maybe = null,
        public int|object $loose = 0,
        /** @var array<string, int|string> */
        #[MapOf('int|string')]
        public array $scores = [],
    ) {
    }
}
