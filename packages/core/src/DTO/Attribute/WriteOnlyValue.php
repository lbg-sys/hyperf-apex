<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

/**
 * 标记字段只出现在请求中（JSON Schema writeOnly），如明文密码、验证码等不回显的字段。
 * 类名不用 WriteOnly，是为了与 ReadOnlyValue 保持一致的命名。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class WriteOnlyValue implements SchemaAttribute
{
    public function __construct(public readonly bool $value = true)
    {
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['writeOnly' => $this->value]);
    }
}
