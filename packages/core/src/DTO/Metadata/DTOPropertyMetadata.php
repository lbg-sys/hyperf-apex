<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Metadata;

use Hyperf\Apex\DTO\Contract\ItemRuleAttribute;
use Hyperf\Apex\DTO\Contract\ValidationRuleAttribute;
use ReflectionAttribute;
use ReflectionType;

final readonly class DTOPropertyMetadata
{
    /**
     * @param list<ReflectionAttribute<ValidationRuleAttribute>> $validationAttributes
     * @param list<ReflectionAttribute<ItemRuleAttribute>> $itemAttributes
     */
    public function __construct(
        public ?ReflectionType $type,
        public bool $required,
        public bool $nullable,
        public ?TypeExpression $itemType,
        public array $validationAttributes,
        public array $itemAttributes,
    ) {
    }
}
