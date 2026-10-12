<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\DTO;

use Hyperf\Apex\DTO\Metadata\TypeExpression;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * 类型表达式对「可空 / 联合」写法的处理。
 *
 * 这两种是 PHP 自己的写法（`?Foo`、`Foo|Bar`），类型表达式不支持 —— 可空的表达位置是属性的
 * PHP 类型。此前 `?FooDTO` 会被当成类名一路走到「类请写完整类名（如 \App\Foo\DTO\BarDTO）」，
 * 写的人于是去反复检查类名，而真实原因完全在别处。
 */
final class TypeExpressionErrorsTest extends TestCase
{
    public function testNullableSyntaxIsRejectedWithItsOwnReason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('可空 / 联合写法不受支持');

        TypeExpression::parse('?FooDTO');
    }

    public function testUnionSyntaxIsRejectedWithItsOwnReason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('可空 / 联合写法不受支持');

        TypeExpression::parse('FooDTO|BarDTO');
    }

    public function testNestedNullableSyntaxIsRejectedToo(): void
    {
        // 嵌套写法同样落在叶子判定上：`?Foo[]`、`list<?Foo>`、`array<string, ?Foo>`。
        foreach (['?Foo[]', 'list<?Foo>', 'array<string, ?Foo>'] as $expression) {
            $this->assertNull(TypeExpression::tryParse($expression), $expression);
        }
    }

    public function testOtherFailuresKeepTheGenericHint(): void
    {
        // 与可空 / 联合无关的失败仍给通用提示（含「支持哪些写法」）。
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('无法解析的类型表达式 Collection<Foo>');

        TypeExpression::parse('Collection<Foo>');
    }

    public function testHotPathStillFallsBackToALeaf(): void
    {
        // parseOrLeaf() 用在请求热路径上：认不出的写法退化成「按类型名原样处理」，永不抛异常。
        // 严格化只针对 parse()，这条容忍度不能被顺手改掉。
        $expression = TypeExpression::parseOrLeaf('?FooDTO');

        $this->assertTrue($expression->isLeaf());
        $this->assertSame('?FooDTO', $expression->name);
    }
}
