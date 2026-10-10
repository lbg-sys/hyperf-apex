<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use InvalidArgumentException;

/**
 * 对象属性数量范围（JSON Schema minProperties / maxProperties），用于键名不固定的映射结构。
 * 注意与 #[ArraySize]（数组元素数量）区分：本 Attribute 约束的是对象的键个数。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class PropertyCount implements SchemaAttribute
{
    public function __construct(
        public readonly ?int $min = null,
        public readonly ?int $max = null,
    ) {
    }

    public function fragment(): SchemaFragment
    {
        if ($this->min !== null && $this->min < 0) {
            throw new InvalidArgumentException('minProperties 不能小于 0。');
        }

        if ($this->max !== null && $this->max < 0) {
            throw new InvalidArgumentException('maxProperties 不能小于 0。');
        }

        if ($this->min !== null && $this->max !== null && $this->min > $this->max) {
            throw new InvalidArgumentException('minProperties 不能大于 maxProperties。');
        }

        // 同时给出 type: object —— PHP 的 array 属性会被推断成 type: array，
        // 而属性数量约束只对对象有意义，靠浅覆盖把类型纠正过来。
        return SchemaFragment::ofPresent([
            'type' => 'object',
            'minProperties' => $this->min,
            'maxProperties' => $this->max,
        ]);
    }
}
