<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use Hyperf\Apex\DTO\Schema\SchemaReference;

/**
 * 字段值满足任意一个给定 Schema 即可（允许同时满足多个）。
 *
 * 与 #[OneOf] 的区别：oneOf 要求「恰好满足一个」，anyOf 允许重叠。
 * 例：#[AnyOf('string', 'integer')]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class AnyOf implements SchemaAttribute
{
    /** @var list<string> */
    public readonly array $types;

    public function __construct(string ...$types)
    {
        $this->types = array_values($types);
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['anyOf' => SchemaReference::all($this->types)]);
    }
}
