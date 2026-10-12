<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\DocumentationOnlyAttribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

/**
 * 通用格式标注（JSON Schema format），如 uuid、email、uri、ipv4、hostname 等。
 * 只影响文档语义，不产生校验规则；日期时间字段请继续使用 #[DateFormat]。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Format implements SchemaAttribute, DocumentationOnlyAttribute
{
    public function __construct(public readonly string $value)
    {
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['format' => $this->value]);
    }
}
