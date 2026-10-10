<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use Hyperf\Apex\DTO\Schema\SchemaReference;

/**
 * 键名不固定的映射结构（`array<string, T>`），值类型由 $type 指定。
 *
 * $type 是类型表达式：标量类型名、DTO 类名、枚举类名，或 list<X> / array<string, X>
 * 这类嵌套写法（见 DTO\TypeExpression）；'mixed' 表示不约束值类型。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class MapOf implements SchemaAttribute
{
    public function __construct(public readonly string $type = 'mixed')
    {
    }

    /**
     * type 必须显式给出：属性的 PHP 类型是 array，类型推断会得到 type: array，
     * 这里靠 SchemaFragment 的浅覆盖语义把它盖成 object。
     */
    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of([
            'type' => 'object',
            // 值类型走与 #[ArrayOf] 相同的解析：DTO 出 $ref，枚举展开成 {key, name}。
            'additionalProperties' => $this->type === 'mixed' ? true : SchemaReference::to($this->type),
        ]);
    }
}
