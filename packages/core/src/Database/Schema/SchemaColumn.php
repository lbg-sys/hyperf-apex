<?php

declare(strict_types=1);

namespace Hyperf\Apex\Database\Schema;

/**
 * 数据库列的一致性检查快照，只保留检查需要的元数据。
 *
 * 刻意不依赖 Hyperf 的 `Schema\Column`：校验逻辑要能在不连库的情况下单测，
 * 值对象越薄，测试构造越直接。
 */
final readonly class SchemaColumn
{
    public function __construct(
        public string $name,
        public string $type,
        public string $comment,
    ) {
    }

    public function hasComment(): bool
    {
        return trim($this->comment) !== '';
    }
}
