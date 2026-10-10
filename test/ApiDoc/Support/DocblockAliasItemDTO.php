<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\Support;

use Hyperf\Apex\DTO\Attribute\FieldDescription;

/**
 * 只被 `@return` 注释引用的 DTO。
 *
 * 存在的目的是让「注释里的短类名要能还原成完整类名」这条路径真的有东西可还原：
 * 它和引用它的用例不在同一个命名空间，也不是同一个文件。
 */
final readonly class DocblockAliasItemDTO
{
    public function __construct(
        #[FieldDescription('名称')]
        public string $name = '',
    ) {
    }
}
