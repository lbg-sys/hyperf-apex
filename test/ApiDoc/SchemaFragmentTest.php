<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\DTO\Schema\SchemaFragment;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * 片段合并语义的契约：浅覆盖 + description 追加 + x 按内层键合并。
 *
 * 这三条是 Attribute 层与编译层之间最容易改坏的约定：改成深合并、或让「已存在的键不覆盖」，
 * 文档会静默产出与校验规则不一致的结构（#[MapOf] 把数组盖成对象就依赖浅覆盖）。
 */
final class SchemaFragmentTest extends TestCase
{
    public function testMergeOverwritesShallowlyAndKeepsOtherKeys(): void
    {
        $base = SchemaFragment::of(['type' => 'array', 'default' => []]);
        $merged = $base->merge(SchemaFragment::of(['type' => 'object', 'additionalProperties' => true]));

        $this->assertSame('object', $merged->get('type'));
        $this->assertTrue($merged->get('additionalProperties'));
        // 没被覆盖的键原样保留。
        $this->assertSame([], $merged->get('default'));

        // 值对象：合并不修改原片段，否则 Attribute 之间会互相污染。
        $this->assertSame('array', $base->get('type'));
    }

    public function testDescriptionIsAppendedNotOverwritten(): void
    {
        $merged = SchemaFragment::of(['description' => '字段含义'])
            ->merge(SchemaFragment::of(['description' => '可选值：1=正常；2=停用']));

        $this->assertSame("字段含义\n可选值：1=正常；2=停用", $merged->get('description'));
    }

    public function testMerchantExtensionsAreMergedByInnerKey(): void
    {
        $merged = SchemaFragment::of(['x' => ['enum-descriptions' => ['正常'], 'keep' => 1]])
            ->merge(SchemaFragment::of(['x' => ['enum-varnames' => ['ENABLED']]]));

        $this->assertSame(
            ['enum-descriptions' => ['正常'], 'keep' => 1, 'enum-varnames' => ['ENABLED']],
            $merged->get('x'),
        );
    }

    public function testUnknownKeywordIsRejectedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('未知的 Schema 关键字 minimumm');

        SchemaFragment::of(['minimumm' => 1]);
    }

    public function testPresentSkipsNullsWhileExplicitNullStaysMeaningful(): void
    {
        $this->assertSame(
            ['minLength' => 1],
            SchemaFragment::ofPresent(['minLength' => 1, 'maxLength' => null])->keywords(),
        );

        // 键存在且为 null 与键不存在是两种状态：default: null / const: null 必须能表达。
        $fragment = SchemaFragment::of(['default' => null]);
        $this->assertTrue($fragment->has('default'));
        $this->assertNull($fragment->get('default'));
        $this->assertSame('fallback', SchemaFragment::of([])->get('default', 'fallback'));
    }
}
