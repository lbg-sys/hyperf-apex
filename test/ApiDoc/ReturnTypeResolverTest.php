<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\ApiDoc\Builder\ReturnTypeResolver;
use Hyperf\Apex\ApiDoc\DTOSchemaFactory;
use Hyperf\Apex\DTO\Attribute\ArrayOf;
use Hyperf\Apex\DTO\Attribute\FieldDescription;
use Hyperf\Apex\DTO\PaginationResultDTO;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use Hyperf\Apex\Test\ApiDoc\Support\DocblockAliasItemDTO as DocblockAlias;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * 返回类型 → 响应 data 的推导契约。
 *
 * 两类判断都要钉住：
 * - 能表达的形态：`void` → `type: null`、具名 DTO → `$ref`、`array` 走 `#[ArrayOf]` 或 `@return`、
 *   联合类型 → `oneOf`、泛型 DTO 走方法级 `#[ArrayOf(..., property:)]` 注册成独立组件；
 * - 表达不出来的形态必须停在构建期：漏写返回类型、裸 `array` 没有元素类型、`property:` 指向
 *   不存在的属性 / 非数组属性 / 重复声明。它们若被放过，产出的就是「语法合法但语义骗人」的文档。
 */
final class ReturnTypeResolverTest extends TestCase
{
    public function testVoidReturnMeansNoBusinessData(): void
    {
        // 用 type: null 精确表达「没有业务数据」，而不是留一个空 schema。
        $this->assertSame(['type' => 'null'], $this->fragment('voidReturn')->keywords());
    }

    public function testNamedDtoReturnBecomesReference(): void
    {
        $this->assertSame(
            ['ref' => '#/components/schemas/ResolverItemDTO'],
            $this->fragment('itemReturn')->keywords(),
        );
    }

    public function testNullableDtoReturnIsMarked(): void
    {
        $fragment = $this->fragment('nullableItemReturn');

        $this->assertSame('#/components/schemas/ResolverItemDTO', $fragment->get('ref'));
        $this->assertTrue($fragment->get('nullable'));
    }

    public function testArrayReturnUsesMethodLevelArrayOf(): void
    {
        $fragment = $this->fragment('arrayWithAttribute');

        $this->assertSame('array', $fragment->get('type'));
        $this->assertSame('#/components/schemas/ResolverItemDTO', $this->items($fragment)->get('ref'));
    }

    public function testArrayReturnFallsBackToDocblockAlias(): void
    {
        // 注释里写的是别名，必须按文件的 use 语句还原成完整类名。
        $fragment = $this->fragment('arrayWithDocblockAlias');

        $this->assertSame('array', $fragment->get('type'));
        $this->assertSame('#/components/schemas/DocblockAliasItemDTO', $this->items($fragment)->get('ref'));
    }

    public function testArrayReturnWithShapeDocblockDegradesToPlainArray(): void
    {
        // array{...} 形状无法归约成元素类型，但「它是数组」这层信息是可靠的。
        $this->assertSame(['type' => 'array'], $this->fragment('arrayWithShapeDocblock')->keywords());
    }

    public function testUnionReturnBecomesOneOf(): void
    {
        $fragment = $this->fragment('unionReturn');

        $oneOf = $fragment->get('oneOf');
        $this->assertIsArray($oneOf);
        $this->assertSame(
            [['ref' => '#/components/schemas/ResolverItemDTO'], ['type' => 'string']],
            array_map(static fn (SchemaFragment $part): array => $part->keywords(), $oneOf),
        );
    }

    public function testGenericPaginationRegistersItsOwnSpecialization(): void
    {
        $schemas = new DTOSchemaFactory();
        $fragment = (new ReturnTypeResolver($schemas))->fragmentFor($this->method('paginationReturn'));

        $name = 'PaginationResultDTO_list_ResolverItemDTO';
        $this->assertSame(['ref' => '#/components/schemas/' . $name], $fragment->keywords());

        // 组件名把属性名也编进去：同一个基础 DTO 的两个属性配同样元素类型时不会撞名。
        $component = $schemas->componentFragments()[$name];
        $this->assertSame(
            '#/components/schemas/ResolverItemDTO',
            $this->items($this->properties($component)['list'])->get('ref'),
        );
    }

    public function testMissingReturnTypeIsRejected(): void
    {
        // 不写返回类型只能产出无约束的 data: {}，那等于什么都没描述。
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('必须声明可表达的返回类型');

        $this->fragment('noReturnType');
    }

    public function testBareArrayWithoutItemTypeIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('必须用方法级 #[ArrayOf(ItemDTO::class)] 或 @return 注释声明元素类型');

        $this->fragment('arrayWithoutItemType');
    }

    public function testPropertyItemTypeOnMissingPropertyIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Response property %s::nonexistent does not exist.', ResolverItemDTO::class));

        $this->fragment('propertyMissing');
    }

    public function testPropertyItemTypeOnNonArrayPropertyIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Response property %s::name must be an array.', ResolverItemDTO::class));

        $this->fragment('propertyNotArray');
    }

    public function testDuplicatePropertyItemTypesAreRejected(): void
    {
        // 同一个属性声明两次元素类型时结果取决于顺序，必须报错而不是挑一个。
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has multiple array item types.');

        $this->fragment('propertyDuplicated');
    }

    public function testPropertyItemTypeOnNonClassReturnIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('不是可反射的类');

        $this->fragment('propertyOnScalar');
    }

    private function fragment(string $method): SchemaFragment
    {
        return (new ReturnTypeResolver(new DTOSchemaFactory()))->fragmentFor($this->method($method));
    }

    private function method(string $name): ReflectionMethod
    {
        return new ReflectionMethod(ReturnTypeFixture::class, $name);
    }

    private function items(SchemaFragment $fragment): SchemaFragment
    {
        $items = $fragment->get('items');
        $this->assertInstanceOf(SchemaFragment::class, $items, '数组片段应当带 items。');

        return $items;
    }

    /**
     * @return array<string, SchemaFragment>
     */
    private function properties(SchemaFragment $fragment): array
    {
        $properties = $fragment->get('properties');
        $this->assertIsArray($properties);

        return $properties;
    }
}

/**
 * 各种返回类型的载体。方法体不会被调用 —— 本组用例只反射签名。
 */
final class ReturnTypeFixture
{
    public function noReturnType()
    {
        throw new RuntimeException('本用例只反射签名。');
    }

    public function voidReturn(): void
    {
    }

    public function itemReturn(): ResolverItemDTO
    {
        throw new RuntimeException('本用例只反射签名。');
    }

    public function nullableItemReturn(): ?ResolverItemDTO
    {
        return null;
    }

    public function arrayWithoutItemType(): array
    {
        return [];
    }

    #[ArrayOf(ResolverItemDTO::class)]
    public function arrayWithAttribute(): array
    {
        return [];
    }

    /** @return list<DocblockAlias> */
    public function arrayWithDocblockAlias(): array
    {
        return [];
    }

    /** @return array{name: string} */
    public function arrayWithShapeDocblock(): array
    {
        return [];
    }

    public function unionReturn(): ResolverItemDTO|string
    {
        return '';
    }

    #[ArrayOf(ResolverItemDTO::class, property: 'list')]
    public function paginationReturn(): PaginationResultDTO
    {
        throw new RuntimeException('本用例只反射签名。');
    }

    #[ArrayOf(ResolverItemDTO::class, property: 'nonexistent')]
    public function propertyMissing(): ResolverItemDTO
    {
        throw new RuntimeException('本用例只反射签名。');
    }

    #[ArrayOf(ResolverItemDTO::class, property: 'name')]
    public function propertyNotArray(): ResolverItemDTO
    {
        throw new RuntimeException('本用例只反射签名。');
    }

    #[ArrayOf(ResolverItemDTO::class, property: 'list')]
    #[ArrayOf(ResolverItemDTO::class, property: 'list')]
    public function propertyDuplicated(): PaginationResultDTO
    {
        throw new RuntimeException('本用例只反射签名。');
    }

    #[ArrayOf(ResolverItemDTO::class, property: 'list')]
    public function propertyOnScalar(): string
    {
        return '';
    }
}

final readonly class ResolverItemDTO
{
    public function __construct(
        #[FieldDescription('名称')]
        public string $name = '',
    ) {
    }
}
