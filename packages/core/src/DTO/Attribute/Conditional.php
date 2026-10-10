<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use Hyperf\Apex\DTO\Schema\SchemaReference;
use InvalidArgumentException;

/**
 * 条件校验：满足 if 的值必须同时满足 then，否则必须满足 else。
 *
 * 三个参数都是类型引用（DTO 类名或标量类型名）。if 与 then 至少要有一个组合有意义，
 * 因此单独给出 then/else 而不给 if 会报错。
 * 例：#[Conditional(if: CronTriggerDTO::class, then: CronFieldsDTO::class)]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Conditional implements SchemaAttribute
{
    public function __construct(
        public readonly ?string $if = null,
        public readonly ?string $then = null,
        public readonly ?string $else = null,
    ) {
    }

    public function fragment(): SchemaFragment
    {
        if ($this->if === null) {
            throw new InvalidArgumentException('#[Conditional] 必须给出 if 分支。');
        }
        if ($this->then === null && $this->else === null) {
            throw new InvalidArgumentException('#[Conditional] 至少要给出 then 或 else 之一。');
        }

        return SchemaFragment::ofPresent([
            'if' => SchemaReference::to($this->if),
            'then' => $this->then === null ? null : SchemaReference::to($this->then),
            'else' => $this->else === null ? null : SchemaReference::to($this->else),
        ]);
    }
}
