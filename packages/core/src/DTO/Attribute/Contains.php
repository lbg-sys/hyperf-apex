<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use Hyperf\Apex\DTO\Schema\SchemaReference;

/**
 * 数组中至少（最多）多少个元素满足给定 Schema。
 *
 * 与 #[ArrayOf] 的区别：ArrayOf 约束「全部元素」的类型，Contains 只要求「存在若干个」。
 * 例：#[Contains(AdminUserDTO::class, min: 1)] 表示列表里至少有一个管理员。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Contains implements SchemaAttribute
{
    public function __construct(
        public readonly string $type,
        public readonly ?int $min = null,
        public readonly ?int $max = null,
    ) {
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::ofPresent([
            'contains' => SchemaReference::to($this->type),
            'minContains' => $this->min,
            'maxContains' => $this->max,
        ]);
    }
}
