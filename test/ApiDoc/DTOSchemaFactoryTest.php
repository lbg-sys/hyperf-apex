<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\ApiDoc\DTOSchemaFactory;
use Hyperf\Apex\DTO\Attribute\ArrayOf;
use Hyperf\Apex\DTO\Attribute\DateFormat;
use Hyperf\Apex\DTO\Attribute\EnumValue;
use Hyperf\Apex\DTO\Attribute\FieldDescription;
use Hyperf\Apex\DTO\Attribute\Format;
use Hyperf\Apex\DTO\Attribute\IntAsString;
use Hyperf\Apex\DTO\Attribute\Length;
use Hyperf\Apex\DTO\Attribute\MapOf;
use Hyperf\Apex\DTO\Attribute\PropertyCount;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use Hyperf\Apex\Enum\Interface\EnumInterface;
use Hyperf\Apex\Enum\Traits\EnumTrait;
use Hyperf\Apex\Test\ApiDoc\Support\Alpha\DuplicateNameDTO as AlphaDuplicateNameDTO;
use Hyperf\Apex\Test\ApiDoc\Support\Beta\DuplicateNameDTO as BetaDuplicateNameDTO;
use Hyperf\Constants\Annotation\Constants;
use Hyperf\Constants\Annotation\Message;
use JsonSerializable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Schema 生成的两类契约：.
 *
 * 1. 合并后的形态 —— 浅覆盖（#[MapOf] 把 array 盖成 object）、description 追加、可空枚举补 null、
 *    构造器默认值补 default；
 * 2. 构建期必须报错的写法 —— 抢同一个关键字、与 type 不匹配的死约束、标错类型的 Attribute、
 *    重复的 #[ArrayOf]、接口/抽象类、内层短类名。
 *
 * 第 2 类刻意不是「兜底成 type: string」：语法合法但语义骗人的文档，比生成失败危险得多。
 */
final class DTOSchemaFactoryTest extends TestCase
{
    public function testMapOfOverwritesInferredArrayTypeAndKeepsDescription(): void
    {
        $properties = $this->properties(MapPropertyDTO::class);

        $this->assertSame('object', $properties['extra']->get('type'));
        $this->assertSame('string', $this->fragment($properties['extra'], 'additionalProperties')->get('type'));
        $this->assertSame('扩展字段', $properties['extra']->get('description'));
    }

    public function testFieldDescriptionAndEnumDescriptionAreJoined(): void
    {
        $properties = $this->properties(EnumFieldDTO::class);

        $this->assertSame(['enabled', 'disabled'], $properties['state']->get('enum'));
        $this->assertSame(
            "状态\n可选值：enabled=启用；disabled=停用",
            $properties['state']->get('description'),
        );
    }

    public function testNullableEnumGainsNull(): void
    {
        // enum 是穷尽的：type 允许 null 而 enum 不允许，文档就比服务端更严。
        $this->assertSame(
            ['enabled', 'disabled', null],
            $this->properties(NullableEnumFieldDTO::class)['state']->get('enum'),
        );
    }

    public function testConstructorDefaultsBecomeDefaultKeyword(): void
    {
        $properties = $this->properties(DefaultValueDTO::class);

        $this->assertSame('x', $properties['name']->get('default'));
        // `= null` 补出来的 default 与 nullable 重复，没有信息量。
        $this->assertFalse($properties['note']->has('default'));
        $this->assertTrue($properties['note']->get('nullable'));
    }

    public function testEnumTypedPropertyIsExpandedIntoKeyNameObject(): void
    {
        $state = $this->properties(EnumTypedFieldDTO::class)['state'];
        $inner = $state->get('properties');
        $this->assertIsArray($inner, '枚举片段里应当有 properties。');

        $this->assertSame('object', $state->get('type'));
        $this->assertSame(['key', 'name'], $state->get('required'));
        $this->assertSame(['enabled', 'disabled'], $inner['key']->get('enum'));
        $this->assertSame(['启用', '停用'], $inner['name']->get('enum'));
    }

    public function testListAndMapExpressionsBecomeArraysAndObjects(): void
    {
        $list = (new DTOSchemaFactory())->fragmentForTypeName('list<int>');
        $this->assertSame('array', $list->get('type'));
        $this->assertSame('integer', $this->fragment($list, 'items')->get('type'));

        $map = (new DTOSchemaFactory())->fragmentForTypeName('array<string, string>');
        $this->assertSame('object', $map->get('type'));
        $this->assertSame('string', $this->fragment($map, 'additionalProperties')->get('type'));
    }

    public function testConflictingKeywordsAreRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('都在写 Schema 关键字 format');

        $this->properties(ConflictingFormatDTO::class);
    }

    public function testArrayOfWithShortClassNameIsRejected(): void
    {
        // 字符串里的类名不经过 use 解析：'list<BarDTO>' 拿不到命名空间。此前它静默落成
        // items: {type: string}，而校验（按对象展开）与水合（按对象转型）都不这么认为。
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('类请写完整类名');

        $this->properties(ShortNameArrayDTO::class);
    }

    public function testSameKeywordWithSameValueIsNotAConflict(): void
    {
        // #[MapOf] 与 #[PropertyCount] 都写 type: object，值一致 —— 产出与声明顺序无关，
        // 不该按「重复写关键字」拦下（此前只看关键字是否出现过，同值也报）。
        $properties = $this->properties(MapWithPropertyCountDTO::class);

        $this->assertSame('object', $properties['scores']->get('type'));
        $this->assertSame(1, $properties['scores']->get('minProperties'));
    }

    public function testKeywordThatDoesNotMatchTypeIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('死约束');

        $this->properties(ArrayWithLengthDTO::class);
    }

    public function testTypedAttributeOnWrongPhpTypeIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('只允许标注在 int 类型的属性上');

        $this->properties(IntAsStringOnStringDTO::class);
    }

    public function testDuplicateArrayOfIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('只有第一个会生效');

        $this->properties(DuplicateArrayOfDTO::class);
    }

    public function testInterfaceTypeIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('没有确定的结构');

        $this->properties(InterfaceTypedFieldDTO::class);
    }

    public function testNestedShortClassNameIsRejected(): void
    {
        // 字符串里的类名不参与 use 解析，短类名无从还原 —— 报错，而不是落成 type: string。
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('完整类名');

        (new DTOSchemaFactory())->fragmentForTypeName('list<BarDTO>');
    }

    public function testDuplicateShortClassNameIsRejectedWhenCollectingComponents(): void
    {
        $factory = new DTOSchemaFactory();
        $factory->refFor(AlphaDuplicateNameDTO::class);
        $factory->refFor(BetaDuplicateNameDTO::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('组件名 DuplicateNameDTO 冲突');

        $factory->componentFragments();
    }

    public function testIdenticalSpecializationIsAcceptedAndCollected(): void
    {
        $factory = new DTOSchemaFactory();
        $fragment = SchemaFragment::of(['type' => 'array', 'items' => SchemaFragment::of(['type' => 'string'])]);

        $first = $factory->refForSpecialization('PageDTO_list_ItemDTO', $fragment);
        $second = $factory->refForSpecialization('PageDTO_list_ItemDTO', $fragment);

        $this->assertSame('#/components/schemas/PageDTO_list_ItemDTO', $first->get('ref'));
        $this->assertSame($first->keywords(), $second->keywords());
        $this->assertArrayHasKey('PageDTO_list_ItemDTO', $factory->componentFragments());
    }

    public function testSameSpecializationNameWithDifferentStructureIsRejected(): void
    {
        // 组件名没把区分维度编码进去（例如漏了属性名）时静默保留第一个，
        // 会让第二个接口拿到一份它从不返回的结构。
        $factory = new DTOSchemaFactory();
        $factory->refForSpecialization('PageDTO_list_ItemDTO', SchemaFragment::of(['type' => 'array']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('对应了两种不同的结构');

        $factory->refForSpecialization('PageDTO_list_ItemDTO', SchemaFragment::of(['type' => 'object']));
    }

    public function testSpecializationNameCollidingWithComponentNameIsRejected(): void
    {
        $factory = new DTOSchemaFactory();
        $factory->refFor(DefaultValueDTO::class);
        $factory->refForSpecialization('DefaultValueDTO', SchemaFragment::of(['type' => 'array']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('合成组件名 DefaultValueDTO 与');

        $factory->componentFragments();
    }

    /**
     * @param class-string $class
     * @return array<string, SchemaFragment>
     */
    private function properties(string $class): array
    {
        $properties = (new DTOSchemaFactory())->objectFragment($class)->get('properties');
        $this->assertIsArray($properties, '对象片段里应当有 properties。');

        return $properties;
    }

    /** 取嵌套片段：缺失时直接失败，避免断言落在 null 上假通过。 */
    private function fragment(SchemaFragment $fragment, string $keyword): SchemaFragment
    {
        $value = $fragment->get($keyword);
        $this->assertInstanceOf(SchemaFragment::class, $value, sprintf('片段里应当有 %s。', $keyword));

        return $value;
    }
}

#[Constants]
enum StateFixtureEnum: string implements EnumInterface
{
    use EnumTrait;

    #[Message('启用')]
    case ENABLED = 'enabled';

    #[Message('停用')]
    case DISABLED = 'disabled';
}

final readonly class MapPropertyDTO
{
    public function __construct(
        #[FieldDescription('扩展字段')]
        #[MapOf('string')]
        public array $extra = [],
    ) {
    }
}

final readonly class EnumFieldDTO
{
    public function __construct(
        #[FieldDescription('状态')]
        #[EnumValue(StateFixtureEnum::class)]
        public string $state = '',
    ) {
    }
}

final readonly class NullableEnumFieldDTO
{
    public function __construct(
        #[EnumValue(StateFixtureEnum::class)]
        public ?string $state = null,
    ) {
    }
}

final readonly class EnumTypedFieldDTO
{
    public function __construct(
        public StateFixtureEnum $state = StateFixtureEnum::ENABLED,
    ) {
    }
}

final readonly class DefaultValueDTO
{
    public function __construct(
        public string $name = 'x',
        public ?string $note = null,
    ) {
    }
}

/** 内层只写短类名：拿不到命名空间，必须在生成期拒绝。 */
final readonly class ShortNameArrayDTO
{
    public function __construct(
        #[FieldDescription('条目')]
        #[ArrayOf('ResolverItemDTO')]
        public array $items = [],
    ) {
    }
}

/** 两个 Attribute 写同一个关键字但值一致：不是冲突。 */
final readonly class MapWithPropertyCountDTO
{
    /** @param array<string, int> $scores */
    public function __construct(
        #[MapOf('int')]
        #[PropertyCount(min: 1)]
        public array $scores = [],
    ) {
    }
}

final readonly class ConflictingFormatDTO
{
    public function __construct(
        #[Format('date-time')]
        #[DateFormat(openApiFormat: 'date')]
        public string $time = '',
    ) {
    }
}

final readonly class ArrayWithLengthDTO
{
    public function __construct(
        #[Length(min: 1)]
        public array $tags = [],
    ) {
    }
}

final readonly class IntAsStringOnStringDTO
{
    public function __construct(
        #[IntAsString]
        public string $id = '',
    ) {
    }
}

final readonly class DuplicateArrayOfDTO
{
    public function __construct(
        #[ArrayOf('string')]
        #[ArrayOf('int')]
        public array $ids = [],
    ) {
    }
}

final readonly class InterfaceTypedFieldDTO
{
    public function __construct(
        public JsonSerializable $payload,
    ) {
    }
}
