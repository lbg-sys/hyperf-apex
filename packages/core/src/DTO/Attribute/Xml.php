<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\DocumentationOnlyAttribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

/**
 * XML 序列化提示。本项目接口只输出 JSON，此 Attribute 仅供需要同时提供
 * XML 表述的对接场景使用，正常业务字段不需要标注。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Xml implements SchemaAttribute, DocumentationOnlyAttribute
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $namespace = null,
        public readonly ?string $prefix = null,
        public readonly ?bool $attribute = null,
        public readonly ?bool $wrapped = null,
    ) {
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of([
            'xml' => array_filter([
                'name' => $this->name,
                'namespace' => $this->namespace,
                'prefix' => $this->prefix,
                'attribute' => $this->attribute,
                'wrapped' => $this->wrapped,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }
}
