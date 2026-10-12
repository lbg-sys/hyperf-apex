<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\DocumentationOnlyAttribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use Hyperf\Apex\DTO\Schema\SchemaReference;

/**
 * 元组校验：按位置逐个约束数组前若干个元素的类型。
 *
 * 例：#[PrefixItems('number', 'number')] 描述一个 [经度, 纬度] 坐标对。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class PrefixItems implements SchemaAttribute, DocumentationOnlyAttribute
{
    /** @var list<string> */
    public readonly array $types;

    public function __construct(string ...$types)
    {
        $this->types = array_values($types);
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['prefixItems' => SchemaReference::all($this->types)]);
    }
}
