<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

/**
 * 字符串内容的媒体类型（JSON Schema contentMediaType），说明这段字符串解码后是什么内容。
 * 常与 #[ContentEncoding] 搭配描述 base64 编码的二进制内容，例如
 * contentEncoding: base64 + contentMediaType: image/png。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ContentMediaType implements SchemaAttribute
{
    public function __construct(public readonly string $value)
    {
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['contentMediaType' => $this->value]);
    }
}
