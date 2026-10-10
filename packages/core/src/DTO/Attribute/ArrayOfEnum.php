<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\ArrayOfAttribute;
use Hyperf\Apex\DTO\Contract\ItemRuleAttribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use Hyperf\Apex\Enum\Interface\EnumInterface;
use Hyperf\Apex\Validation\Rule\EnumValueRule;

/**
 * 元素是枚举 backing value 的数组，用于请求侧的 `list<enum>`。
 *
 * 替代 `#[ArrayOf('string')]`，不要和它同时标注。三层语义一致：
 * - 文档：`items` 带上 `enum`、中文值说明和 `x-enum-*`
 * - 校验：`字段.*` 挂枚举规则（兼容 int-backed 枚举收到的数字字符串），非法元素在参数水合阶段就被拒绝
 * - 水合：元素按枚举的 backing 类型转型
 *
 * 请求侧刻意用 backing value 而不是 `{key, name}` 对象 —— 与标量字段上
 * 「`string` 类型 + `#[EnumValue]`」的既有写法保持一致。响应侧返回枚举数组时
 * 仍然用 `#[ArrayOf(SomeEnum::class)]`，那里的元素是 EnumTrait 序列化出的对象。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ArrayOfEnum implements ArrayOfAttribute, ItemRuleAttribute, SchemaAttribute
{
    public function __construct(public readonly string $enum)
    {
    }

    public function itemType(): string
    {
        return self::backingType($this->enum);
    }

    public function targetProperty(): ?string
    {
        return null;
    }

    public function itemValidationRules(): array
    {
        return [new EnumValueRule($this->enum)];
    }

    /**
     * 枚举合法性由 EnumValue::fragment() 校验并抛异常，因此这里复用它 ——
     * 「请求侧枚举长什么样」只保留一份实现。
     */
    public function fragment(): SchemaFragment
    {
        $values = (new EnumValue($this->enum))->fragment();

        return SchemaFragment::of([
            'type' => 'array',
            'items' => $values->with('type', $this->itemType() === 'int' ? 'integer' : 'string'),
        ]);
    }

    /**
     * 取 backing 类型。刻意不用 ReflectionEnum：itemType() 在 HTTP 请求热路径上被调用。
     * 传了非枚举时不在这里报错，交给 fragment() 在文档构建期响亮失败。
     */
    private static function backingType(string $enum): string
    {
        if (! is_a($enum, EnumInterface::class, true)) {
            return 'string';
        }

        $cases = $enum::cases();

        return $cases !== [] && is_int($cases[0]->value) ? 'int' : 'string';
    }
}
