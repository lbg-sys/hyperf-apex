<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\ApiDoc\Compiler\SchemaFragmentTranslator;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use OpenApi\Spec as OA;
use OpenApi\Undefined;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 片段 → swagger-php Spec 的翻译契约。
 *
 * 这是全项目唯一知道 swagger-php 结构的地方，三类关键字各有各的换形方式：
 * 值是 Schema 的、值是 Schema 列表的、值是「名称 => Schema」映射的，还有 properties 要从
 * 「属性名 => 片段」换成 `list<Property>`。断言按这三类分开写，改错一类不会连带遮住另一类。
 *
 * 另一条要钉住的是「未设置」与「显式 null」的区分：swagger-php 用 Undefined 哨兵表达前者，
 * 具名实参展开正是为了不把未设置的关键字写成 null。
 */
final class SchemaFragmentTranslatorTest extends TestCase
{
    public function testScalarKeywordsPassThrough(): void
    {
        $schema = $this->translate(SchemaFragment::of([
            'type' => 'string',
            'format' => 'date-time',
            'minLength' => 1,
            'maxLength' => 32,
            'nullable' => true,
        ]));

        $this->assertSame('string', $schema->type);
        $this->assertSame('date-time', $schema->format);
        $this->assertSame(1, $schema->minLength);
        $this->assertSame(32, $schema->maxLength);
        $this->assertTrue($schema->nullable);
    }

    public function testUnsetKeywordsStayUndefinedWhileExplicitNullIsKept(): void
    {
        $unset = $this->translate(SchemaFragment::of(['type' => 'string']));
        $this->assertSame(Undefined::UNDEFINED, $unset->default);
        $this->assertSame(Undefined::UNDEFINED, $unset->const);
        $this->assertSame(Undefined::UNDEFINED, $unset->example);

        // default: null 与「没有 default」是两种不同的文档语义，不能被压成同一个。
        $explicit = $this->translate(SchemaFragment::of(['type' => 'string', 'default' => null, 'const' => null]));
        $this->assertNull($explicit->default);
        $this->assertNull($explicit->const);
    }

    public function testComponentNameIsCarriedAsComponentKey(): void
    {
        $schema = $this->translate(SchemaFragment::of(['type' => 'object']), 'SampleDTO');

        $this->assertSame('SampleDTO', $schema->component);
        // swagger-php 6.12 起 `schema` 作为组件键已弃用（会触发 deprecation），只用 `component`。
        $this->assertNull($schema->schema);
    }

    public function testPropertiesBecomePropertyListInDeclarationOrder(): void
    {
        $schema = $this->translate(SchemaFragment::of([
            'type' => 'object',
            'properties' => [
                'recordId' => SchemaFragment::of(['type' => 'string']),
                'amount' => SchemaFragment::of(['type' => 'integer']),
            ],
            'required' => ['recordId'],
        ]));

        $properties = $schema->properties;
        $this->assertIsArray($properties);
        $this->assertCount(2, $properties);
        $this->assertSame(['recordId', 'amount'], array_map(static fn (OA\Property $item): string => $item->property, $properties));
        $this->assertSame('integer', $properties[1]->schema->type);
        $this->assertSame(['recordId'], $schema->required);
    }

    public function testSchemaValuedKeywordsAreTranslatedRecursively(): void
    {
        $schema = $this->translate(SchemaFragment::of([
            'type' => 'array',
            'items' => SchemaFragment::of(['type' => 'string', 'enum' => ['a', 'b', null]]),
            'additionalProperties' => SchemaFragment::of(['type' => 'integer']),
        ]));

        $this->assertInstanceOf(OA\Schema::class, $schema->items);
        $this->assertSame(['a', 'b', null], $schema->items->enum);
        $this->assertInstanceOf(OA\Schema::class, $schema->additionalProperties);

        // 布尔形态的 additionalProperties（MapOf('mixed') 会写出 true）原样透传，
        // 不能被当成片段处理。
        $this->assertTrue($this->translate(SchemaFragment::of(['additionalProperties' => true]))->additionalProperties);
    }

    public function testSchemaListAndMapValuedKeywordsAreFilledWithSchemas(): void
    {
        $schema = $this->translate(SchemaFragment::of([
            'oneOf' => [
                SchemaFragment::of(['type' => 'string']),
                SchemaFragment::of(['type' => 'integer']),
            ],
            'patternProperties' => [
                '^x-' => SchemaFragment::of(['type' => 'string']),
            ],
        ]));

        $this->assertIsArray($schema->oneOf);
        $this->assertCount(2, $schema->oneOf);
        $this->assertInstanceOf(OA\Schema::class, $schema->oneOf[1]);
        $this->assertSame('integer', $schema->oneOf[1]->type);

        $this->assertIsArray($schema->patternProperties);
        $this->assertInstanceOf(OA\Schema::class, $schema->patternProperties['^x-']);
    }

    public function testObjectValuedKeywordsBecomeSpecObjects(): void
    {
        $schema = $this->translate(SchemaFragment::of([
            'discriminator' => ['propertyName' => 'kind', 'mapping' => ['a' => '#/components/schemas/A']],
            'externalDocs' => ['url' => 'https://example.com/doc', 'description' => '更多说明'],
            'xml' => ['name' => 'record'],
        ]));

        $this->assertInstanceOf(OA\Discriminator::class, $schema->discriminator);
        $this->assertSame('kind', $schema->discriminator->propertyName);
        $this->assertInstanceOf(OA\ExternalDocumentation::class, $schema->externalDocs);
        $this->assertSame('https://example.com/doc', $schema->externalDocs->url);
        $this->assertInstanceOf(OA\Xml::class, $schema->xml);
        $this->assertSame('record', $schema->xml->name);
    }

    public function testMerchantExtensionsAreCarriedToTheSchema(): void
    {
        // x 的展开交给 swagger-php（序列化时输出成 x-{key}），这里只保证不被丢掉。
        $schema = $this->translate(SchemaFragment::of([
            'x' => ['enum-descriptions' => ['启用'], 'enum-varnames' => ['ENABLED']],
        ]));

        $this->assertSame(
            ['enum-descriptions' => ['启用'], 'enum-varnames' => ['ENABLED']],
            $schema->x,
        );
    }

    public function testEmptyFragmentProducesEmptySchema(): void
    {
        $schema = $this->translate(SchemaFragment::empty());

        $this->assertNull($schema->type);
        $this->assertNull($schema->properties);
        $this->assertSame(Undefined::UNDEFINED, $schema->default);
    }

    public function testThreeZeroTargetRejectsThreeOneOnlyKeywords(): void
    {
        // 3.0 编译器对它们没有任何报错，只是不输出 —— 文档看起来正常，实际少了一条声明的约束。
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('3.1 专有关键字 prefixItems');

        $this->translate(
            SchemaFragment::of(['type' => 'array', 'prefixItems' => [SchemaFragment::of(['type' => 'string'])]]),
            'SampleDTO',
            '3.0.3',
        );
    }

    public function testThreeZeroTargetRejectsNestedThreeOneOnlyKeywords(): void
    {
        // items 内层的 patternProperties 同样会被丢掉，所以检查必须递归下去。
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('patternProperties');

        $this->translate(
            SchemaFragment::of([
                'type' => 'object',
                'properties' => [
                    'labels' => SchemaFragment::of([
                        'type' => 'object',
                        'patternProperties' => ['^x-' => SchemaFragment::of(['type' => 'string'])],
                    ]),
                ],
            ]),
            null,
            '3.0.3',
        );
    }

    public function testThreeZeroTargetStillAcceptsDowngradableKeywords(): void
    {
        // const / 类型数组 / examples 在 3.0 里都有等价表达（enum、type + nullable、example），
        // 不该被拦下来 —— 收口只针对真正表达不出来的那批。
        $schema = $this->translate(
            SchemaFragment::of(['type' => ['string', 'null'], 'const' => 'fixed', 'examples' => ['a']]),
            null,
            '3.0.3',
        );

        $this->assertSame(['string', 'null'], $schema->type);
    }

    public function testThreeOneTargetAndUnspecifiedTargetAreNotRestricted(): void
    {
        $fragment = SchemaFragment::of(['type' => 'array', 'prefixItems' => [SchemaFragment::of(['type' => 'string'])]]);

        $this->assertInstanceOf(OA\Schema::class, $this->translate($fragment, null, '3.1.1'));
        // 未指定目标时不做检查：翻译器还有别的直接调用方。
        $this->assertInstanceOf(OA\Schema::class, $this->translate($fragment));
    }

    private function translate(
        SchemaFragment $fragment,
        ?string $componentName = null,
        ?string $targetVersion = null,
    ): OA\Schema {
        return (new SchemaFragmentTranslator($targetVersion))->translate($fragment, $componentName);
    }
}
