<?php

declare(strict_types=1);

namespace Hyperf\Apex\Database\Schema;

/**
 * 单个数据库连接的 schema 快照：表注释与列定义。
 *
 * `information_schema` 返回的是带连接前缀的物理表名，调用方一律传 Model 的逻辑表名，
 * 由本类统一补前缀，避免前缀拼接散落到校验逻辑里。
 */
final readonly class SchemaCatalog
{
    /**
     * @param array<string, string> $tableComments 物理表名 => 表注释
     * @param array<string, array<string, SchemaColumn>> $tableColumns 物理表名 => 列名 => 列
     */
    public function __construct(
        private string $prefix,
        private array $tableComments,
        private array $tableColumns,
    ) {
    }

    public function hasTable(string $table): bool
    {
        $physical = $this->physical($table);

        return array_key_exists($physical, $this->tableColumns) || array_key_exists($physical, $this->tableComments);
    }

    public function tableComment(string $table): string
    {
        return $this->tableComments[$this->physical($table)] ?? '';
    }

    /**
     * @return array<string, SchemaColumn>
     */
    public function columns(string $table): array
    {
        return $this->tableColumns[$this->physical($table)] ?? [];
    }

    private function physical(string $table): string
    {
        return $this->prefix . $table;
    }
}
