<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Contract;

interface ArrayOfAttribute
{
    /**
     * 元素类型表达式。由 DTO\TypeExpression 解析，可以是单个类型名，
     * 也可以是 list<X> / array<string, X> / X[] 这类嵌套写法。
     */
    public function itemType(): string;

    public function targetProperty(): ?string;
}
