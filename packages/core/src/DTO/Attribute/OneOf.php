<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\DocumentationOnlyAttribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use Hyperf\Apex\DTO\Schema\SchemaReference;

/**
 * 字段值必须恰好满足其中一个给定 Schema。
 *
 * PHP 联合类型属性会自动产出 oneOf，这个 Attribute 用于类型无法表达的场景
 * （例如同一个 array 字段可能是两种不同的 DTO 结构）。
 * 例：#[OneOf(TextContentDTO::class, FileContentDTO::class)]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class OneOf implements SchemaAttribute, DocumentationOnlyAttribute
{
    /** @var list<string> */
    public readonly array $types;

    public function __construct(string ...$types)
    {
        $this->types = array_values($types);
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['oneOf' => SchemaReference::all($this->types)]);
    }
}
