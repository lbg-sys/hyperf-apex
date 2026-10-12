<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\DocumentationOnlyAttribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

/**
 * 标记字段只出现在响应中（JSON Schema readOnly），如自增 ID、创建时间等由服务端生成的字段。
 * 类名不用 ReadOnly，因为 readonly 是 PHP 保留字。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ReadOnlyValue implements SchemaAttribute, DocumentationOnlyAttribute
{
    public function __construct(public readonly bool $value = true)
    {
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['readOnly' => $this->value]);
    }
}
