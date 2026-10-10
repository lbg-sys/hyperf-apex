<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\Support\Alpha;

/**
 * 与 `Hyperf\Apex\Test\ApiDoc\Support\Beta\DuplicateNameDTO` 短类名相同。
 *
 * 存在的唯一目的：验证「组件名取短类名」的冲突会在构建期被拦下，而不是后者静默覆盖前者。
 */
final readonly class DuplicateNameDTO
{
    public function __construct(public string $orderId = '')
    {
    }
}
