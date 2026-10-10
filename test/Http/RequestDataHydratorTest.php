<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Http;

use DateTimeImmutable;
use Hyperf\Apex\DTO\Attribute\ArrayOf;
use Hyperf\Apex\DTO\Attribute\FieldDescription;
use Hyperf\Apex\DTO\Attribute\Optional;
use Hyperf\Apex\Http\ArgumentResolver\RequestDataHydrator;
use Hyperf\Support\Filesystem\Filesystem;
use Hyperf\Translation\FileLoader;
use Hyperf\Translation\Translator;
use Hyperf\Validation\ValidationException;
use Hyperf\Validation\ValidatorFactory;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * 水合器的两条职责：先按 DTO 声明的规则校验，再把通过校验的值转成 PHP 类型的实例。
 *
 * 转型这一段是「文档说的类型」与「PHP 拿到的类型」之间的唯一一道关：
 * 递归 DTO、嵌套数组、可空空串、布尔字面量的口径都在这里，逐条钉住。
 */
final class RequestDataHydratorTest extends TestCase
{
    public function testMissingFieldWithDefaultValueUsesTheDefault(): void
    {
        $dto = $this->hydrator()->hydrate(OptionalWithDefaultDTO::class, []);

        $this->assertInstanceOf(OptionalWithDefaultDTO::class, $dto);
        $this->assertSame('', $dto->keyword);
    }

    public function testMissingFieldThatIsNeitherDefaultedNorNullableFailsLoudly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('没有默认值也不可空');

        $this->hydrator()->hydrate(OptionalWithoutDefaultDTO::class, []);
    }

    public function testMissingOptionalNullableFieldBecomesNull(): void
    {
        $dto = $this->hydrator()->hydrate(OptionalNullableDTO::class, []);

        // 可空 + 可选：不传就是 null，而不是「按 0 兜底」那种会被误当成真实值的默认。
        $this->assertNull($dto->page);
    }

    public function testNestedDtoPropertyIsHydratedRecursively(): void
    {
        $dto = $this->hydrator()->hydrate(NestedParentDTO::class, ['child' => ['label' => '子项']]);

        $this->assertInstanceOf(NestedChildDTO::class, $dto->child);
        $this->assertSame('子项', $dto->child->label);
    }

    public function testNestedListIsCastLayerByLayer(): void
    {
        $dto = $this->hydrator()->hydrate(MatrixDTO::class, ['matrix' => [['1', '2'], ['3']]]);

        // 数字串按层次转成 int：`#[ArrayOf('list<int>')]` 的深度承诺在水合侧同样成立。
        $this->assertSame([[1, 2], [3]], $dto->matrix);
    }

    public function testArrayOfDtoItemsBecomeDtoInstances(): void
    {
        $dto = $this->hydrator()->hydrate(ChildListDTO::class, [
            'children' => [['label' => 'a'], ['label' => 'b']],
        ]);

        $this->assertCount(2, $dto->children);
        $this->assertContainsOnlyInstancesOf(NestedChildDTO::class, $dto->children);
        $this->assertSame('b', $dto->children[1]->label);
    }

    public function testEmptyStringBecomesNullForNullableStringField(): void
    {
        // 可空字符串收到空串按「没传」处理；这是刻意口径（见 castBuiltin 注释），不是漏转。
        $this->assertNull($this->hydrator()->hydrate(NullableStringDTO::class, ['note' => ''])->note);
    }

    public function testEmptyStringOnNullableIntBecomesNullWhilePlainIntFallsBack(): void
    {
        /*
         * 空串不会走到 integer 规则：校验器对空值会跳过非隐式规则（`required` 这类才照常执行），
         * 所以两边的差别全在转型这一步 —— 可空转 null，不可空落到该类型的兜底值 0。
         * 空串在 query/form 里就是「这个字段没填」，把它变成 0 会被下游当成真实取值。
         */
        $this->assertNull($this->hydrator()->hydrate(NullableIntDTO::class, ['page' => ''])->page);
        $this->assertSame(0, $this->hydrator()->hydrate(CountDTO::class, ['count' => ''])->count);
    }

    public function testNumericStringBecomesIntAndBooleanAcceptsOnOff(): void
    {
        $this->assertSame(42, $this->hydrator()->hydrate(CountDTO::class, ['count' => '42'])->count);

        // 布尔口径与 BooleanValueRule、RequestDataHydrator 的 castBuiltin 共用 filter_var 语义：
        // query/form 里的 'on' / 'no' 都是合法布尔字面量。
        $this->assertTrue($this->hydrator()->hydrate(FlagDTO::class, ['enabled' => 'on'])->enabled);
        $this->assertFalse($this->hydrator()->hydrate(FlagDTO::class, ['enabled' => 'no'])->enabled);
    }

    public function testUnionTypedParameterIsPassedThroughWithoutCasting(): void
    {
        // 联合类型没有单一目标类型，转型这一步只能原样放行（规则侧也只产出 sometimes）。
        $this->assertSame(42, $this->hydrator()->hydrate(UnionDTO::class, ['value' => 42])->value);
        $this->assertSame('text', $this->hydrator()->hydrate(UnionDTO::class, ['value' => 'text'])->value);
    }

    public function testClassTypedFieldRejectsNonArrayValueAtValidation(): void
    {
        /*
         * 请求 DTO 是「线上形状」：标量 / 数组 / 嵌套 DTO。值对象（DateTimeImmutable 这类）
         * 不是可表达的请求字段 —— 校验层把每个非内置类都当成嵌套 DTO（要求值必须是数组），
         * 在这里以 422 拦下，而不是让字符串漏进构造器变成 TypeError 500。
         * 真要收值对象，字段声明成标量、在 DTO 里自行转换。
         */
        $this->expectException(ValidationException::class);

        $this->hydrator()->hydrate(ValueObjectDTO::class, ['at' => '2026-01-01']);
    }

    private function hydrator(): RequestDataHydrator
    {
        // 语言包目录可以不真实存在：这组用例都不需要翻译文案。
        $translator = new Translator(new FileLoader(new Filesystem(), BASE_PATH . '/runtime/lang'), 'zh_CN');

        return new RequestDataHydrator(new ValidatorFactory($translator));
    }
}

/** 可选且有默认值：请求里不出现该字段是正常情况。 */
final readonly class OptionalWithDefaultDTO
{
    public function __construct(
        #[Optional]
        public string $keyword = '',
    ) {
    }
}

/**
 * 可选、无默认值且不可空：自相矛盾的声明。
 *
 * 校验层按「可选」放行，构造时却只能传 null —— 不做处理就是一个 TypeError 转成的 500，
 * 且日志里只有一句 must be of type string, null given。
 */
final readonly class OptionalWithoutDefaultDTO
{
    public function __construct(
        #[Optional]
        public string $keyword,
    ) {
    }
}

/** 可选、无默认值但可空：不传就是 null。 */
final readonly class OptionalNullableDTO
{
    public function __construct(
        #[Optional]
        public ?int $page,
    ) {
    }
}

final readonly class NullableStringDTO
{
    public function __construct(
        public ?string $note = null,
    ) {
    }
}

final readonly class NullableIntDTO
{
    public function __construct(
        public ?int $page = null,
    ) {
    }
}

final readonly class NestedChildDTO
{
    public function __construct(
        #[FieldDescription('子项名称')]
        public string $label = '',
    ) {
    }
}

final readonly class NestedParentDTO
{
    public function __construct(
        public ?NestedChildDTO $child = null,
    ) {
    }
}

final readonly class MatrixDTO
{
    public function __construct(
        #[ArrayOf('list<int>')]
        public array $matrix = [],
    ) {
    }
}

final readonly class ChildListDTO
{
    public function __construct(
        #[ArrayOf(NestedChildDTO::class)]
        public array $children = [],
    ) {
    }
}

final readonly class CountDTO
{
    public function __construct(
        public int $count = 0,
    ) {
    }
}

final readonly class FlagDTO
{
    public function __construct(
        public bool $enabled = false,
    ) {
    }
}

final readonly class UnionDTO
{
    public function __construct(
        public int|string $value = '',
    ) {
    }
}

/** 值对象字段：水合器不猜测它的构造方式，只做原样透传。 */
final readonly class ValueObjectDTO
{
    public function __construct(
        public DateTimeImmutable $at,
    ) {
    }
}
