<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

/**
 * 字段的补充说明链接，会渲染成 externalDocs。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ExternalDocs implements SchemaAttribute
{
    public function __construct(
        public readonly string $url,
        public readonly string $description = '',
    ) {
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of([
            'externalDocs' => array_filter([
                'url' => $this->url,
                'description' => $this->description !== '' ? $this->description : null,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }
}
