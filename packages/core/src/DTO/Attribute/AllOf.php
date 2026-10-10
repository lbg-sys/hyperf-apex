<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use Hyperf\Apex\DTO\Schema\SchemaReference;

/**
 * 字段值必须同时满足全部给定 Schema，常用于「基础结构 + 附加约束」的叠加。
 *
 * 参数是类型引用：DTO 类名解析成 $ref，标量类型名解析成基础类型。
 * 例：#[AllOf(BaseProfileDTO::class, ExtraProfileDTO::class)]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class AllOf implements SchemaAttribute
{
    /** @var list<string> */
    public readonly array $types;

    public function __construct(string ...$types)
    {
        $this->types = array_values($types);
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['allOf' => SchemaReference::all($this->types)]);
    }
}
