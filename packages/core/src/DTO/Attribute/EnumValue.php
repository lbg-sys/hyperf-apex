<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Contract\ValidationRuleAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use Hyperf\Apex\Enum\Interface\EnumInterface;
use Hyperf\Apex\Validation\Rule\EnumValueRule;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class EnumValue implements ValidationRuleAttribute, SchemaAttribute
{
    public function __construct(public readonly string $enum)
    {
    }

    public function validationRules(): array
    {
        return [new EnumValueRule($this->enum)];
    }

    /**
     * 校验刻意留在这里而不是构造函数：EnumValue 是唯一会抛异常的 Schema Attribute，
     * 放进构造函数会让它在 HTTP 请求期触发 500，而不是停在文档构建期。
     */
    public function fragment(): SchemaFragment
    {
        if (! is_a($this->enum, EnumInterface::class, true)) {
            throw new InvalidArgumentException(sprintf(
                '%s 必须实现 %s：枚举字段的类必须是带 #[Message] 的 backed enum。',
                $this->enum,
                EnumInterface::class
            ));
        }

        $cases = $this->enum::cases();
        $values = array_map(static fn (EnumInterface $case): int|string => $case->value, $cases);
        $descriptions = array_map(static fn (EnumInterface $case): string => $case->getMessage(), $cases);
        $valueDescriptions = array_map(
            static fn (EnumInterface $case): string => sprintf('%s=%s', (string) $case->value, $case->getMessage()),
            $cases
        );

        return SchemaFragment::of([
            'enum' => $values,
            'description' => '可选值：' . implode('；', $valueDescriptions),
            'x' => [
                'enum-descriptions' => $descriptions,
                'enum-varnames' => array_map(static fn (EnumInterface $case): string => $case->name, $cases),
            ],
        ]);
    }
}
