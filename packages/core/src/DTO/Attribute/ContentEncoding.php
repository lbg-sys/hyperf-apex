<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\DocumentationOnlyAttribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

/**
 * 字符串内容的编码方式（JSON Schema contentEncoding），例如 base64、base64url。
 * 常与 #[ContentMediaType] 搭配描述 base64 编码的二进制内容，例如
 * contentEncoding: base64 + contentMediaType: image/png。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ContentEncoding implements SchemaAttribute, DocumentationOnlyAttribute
{
    public function __construct(public readonly string $value)
    {
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['contentEncoding' => $this->value]);
    }
}
