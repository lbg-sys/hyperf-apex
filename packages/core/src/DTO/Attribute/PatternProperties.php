<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\DocumentationOnlyAttribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use Hyperf\Apex\DTO\Schema\SchemaReference;

/**
 * 按键名正则约束对象属性的类型，用于键名有命名规律的映射结构。
 *
 * 与 #[MapOf] 的区别：MapOf 对所有键统一约束值类型，这里可以按正则分组约束。
 * 例：#[PatternProperties(['^count_' => 'integer', '^name_' => 'string'])]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class PatternProperties implements SchemaAttribute, DocumentationOnlyAttribute
{
    /** @param array<string, string> $patterns 正则 => 类型引用 */
    public function __construct(public readonly array $patterns)
    {
    }

    public function fragment(): SchemaFragment
    {
        $schemas = [];
        foreach ($this->patterns as $pattern => $type) {
            $schemas[(string) $pattern] = SchemaReference::to($type);
        }

        return SchemaFragment::of(['type' => 'object', 'patternProperties' => $schemas]);
    }
}
