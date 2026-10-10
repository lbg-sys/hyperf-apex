<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use Hyperf\Apex\DTO\Schema\SchemaReference;

/**
 * 某个键出现时，整个对象额外需要满足的 Schema。
 *
 * 与 #[DependentRequired] 的区别：后者只能追加必填字段名，这里可以追加完整结构约束。
 * 例：#[DependentSchemas(['payMethod' => PaymentDetailDTO::class])]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class DependentSchemas implements SchemaAttribute
{
    /** @param array<string, string> $map 键名 => 类型引用 */
    public function __construct(public readonly array $map)
    {
    }

    public function fragment(): SchemaFragment
    {
        $schemas = [];
        foreach ($this->map as $property => $type) {
            $schemas[(string) $property] = SchemaReference::to($type);
        }

        return SchemaFragment::of(['type' => 'object', 'dependentSchemas' => $schemas]);
    }
}
