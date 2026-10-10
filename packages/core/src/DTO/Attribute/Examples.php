<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

/**
 * 字段的多个示例值（JSON Schema examples）。与只能给一个示例的 #[Example] 互补，
 * 适合需要展示多种典型写法的字段。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Examples implements SchemaAttribute
{
    /** @var array<array-key, mixed> */
    public readonly array $values;

    /**
     * 变长参数无法使用构造器属性提升，这里只做一次直接赋值，不含任何逻辑。
     *
     * @param mixed ...$values 示例值列表
     */
    public function __construct(mixed ...$values)
    {
        $this->values = $values;
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['examples' => $this->values]);
    }
}
