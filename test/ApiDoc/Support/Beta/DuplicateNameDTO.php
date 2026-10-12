<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\Support\Beta;

/**
 * 与 `Hyperf\Apex\Test\ApiDoc\Support\Alpha\DuplicateNameDTO` 短类名相同，属性刻意不同 ——
 * 两个名字一样、结构不一样的 DTO 在文档里无法区分，必须报错让人去改名。
 */
final readonly class DuplicateNameDTO
{
    public function __construct(public string $channelId = '')
    {
    }
}
