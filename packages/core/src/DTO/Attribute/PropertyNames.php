<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\DocumentationOnlyAttribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

/**
 * 约束对象「键名本身」的格式，而不是键对应的值。
 *
 * 例：#[PropertyNames(pattern: '^[a-z][a-z0-9_]*$')] 要求所有键名是小写蛇形。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class PropertyNames implements SchemaAttribute, DocumentationOnlyAttribute
{
    public function __construct(
        public readonly ?string $pattern = null,
        public readonly ?int $minLength = null,
        public readonly ?int $maxLength = null,
    ) {
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of([
            'type' => 'object',
            'propertyNames' => SchemaFragment::ofPresent([
                'type' => 'string',
                'pattern' => $this->pattern,
                'minLength' => $this->minLength,
                'maxLength' => $this->maxLength,
            ]),
        ]);
    }
}
