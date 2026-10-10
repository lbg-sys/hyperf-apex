<?php

declare(strict_types=1);

namespace Hyperf\Apex\Database\Schema;

/**
 * 比对 Model 元数据与数据库 schema，产出不一致清单。
 *
 * 不连库、不写库：输入是两份快照（Model 反射结果 + `information_schema` 结果），
 * 因此可以在单测里用构造的数据覆盖每条规则。
 */
final class SchemaModelChecker
{
    /**
     * 时间字段命名约定的适用列类型。
     */
    private const TIME_COLUMN_TYPES = ['date', 'datetime', 'timestamp', 'time'];

    /**
     * `@property` 注解里能代表数据库列的标量类型；其余（大写开头、非标量）视为关系属性。
     */
    private const SCALAR_PROPERTY_TYPES = [
        'int', 'integer', 'string', 'bool', 'boolean', 'float', 'double', 'real',
        'array', 'list', 'iterable', 'mixed', 'object', 'callable',
        'carbon', 'carbon\carbon', 'carbon\carbonimmutable',
        'datetime', 'datetimeimmutable', 'datetimeinterface',
    ];

    private const INTEGER_COLUMN_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'bit', 'year'];

    private const STRING_COLUMN_TYPES = [
        'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext',
        'enum', 'set', 'binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob',
    ];

    private const BOOLEAN_COLUMN_TYPES = ['tinyint', 'bit', 'boolean'];

    private const NUMERIC_COLUMN_TYPES = [
        'decimal', 'numeric', 'float', 'double', 'real',
        'int', 'bigint', 'smallint', 'mediumint', 'tinyint',
    ];

    private const JSON_COLUMN_TYPES = ['json', 'text', 'tinytext', 'mediumtext', 'longtext', 'varchar', 'char'];

    /**
     * `$casts` 基础类型 => 允许的列类型。未收录的 cast（枚举、自定义类等）不做类型校验。
     */
    private const CAST_COLUMN_TYPES = [
        'int' => self::INTEGER_COLUMN_TYPES,
        'integer' => self::INTEGER_COLUMN_TYPES,
        'string' => self::STRING_COLUMN_TYPES,
        'bool' => self::BOOLEAN_COLUMN_TYPES,
        'boolean' => self::BOOLEAN_COLUMN_TYPES,
        'float' => self::NUMERIC_COLUMN_TYPES,
        'double' => self::NUMERIC_COLUMN_TYPES,
        'real' => self::NUMERIC_COLUMN_TYPES,
        'decimal' => self::NUMERIC_COLUMN_TYPES,
        'date' => ['date'],
        'datetime' => ['datetime', 'timestamp'],
        'immutable_datetime' => ['datetime', 'timestamp'],
        'immutable_date' => ['date'],
        'timestamp' => ['timestamp', 'datetime'],
        'array' => self::JSON_COLUMN_TYPES,
        'json' => self::JSON_COLUMN_TYPES,
        'object' => self::JSON_COLUMN_TYPES,
        'collection' => self::JSON_COLUMN_TYPES,
    ];

    /**
     * @param list<ModelMetadata> $models
     * @param array<string, SchemaCatalog> $catalogs
     * @return list<SchemaModelIssue>
     */
    public function check(array $models, array $catalogs): array
    {
        $issues = [];

        foreach ($models as $model) {
            $catalog = $catalogs[$model->connection] ?? null;
            if (! $catalog instanceof SchemaCatalog) {
                $issues[] = $this->error($model, null, sprintf('缺少连接 %s 的 schema 快照', $model->connection));

                continue;
            }

            if (! $catalog->hasTable($model->table)) {
                $issues[] = $this->error(
                    $model,
                    null,
                    sprintf('数据库表 %s 不存在（迁移未执行或表名配置错误）', $model->table)
                );

                continue;
            }

            $columns = $catalog->columns($model->table);

            if (trim($catalog->tableComment($model->table)) === '') {
                $issues[] = $this->error($model, null, '数据表缺少中文注释');
            }

            foreach ($columns as $column) {
                if (! $column->hasComment()) {
                    $issues[] = $this->error($model, $column->name, '字段缺少中文注释');
                }

                // 列类型来自 information_schema（SchemaInspector 已小写化），但本类也接受外部快照，
                // 这里再小写一次，避免大写类型（如 DATETIME）让时间字段命名约定与 cast 校验静默失效。
                if (in_array(strtolower($column->type), self::TIME_COLUMN_TYPES, true) && ! str_ends_with($column->name, '_time')) {
                    $issues[] = $this->warning($model, $column->name, '时间字段命名应以 _time 结尾');
                }
            }

            foreach (array_keys($columns) as $columnName) {
                if (! isset($model->properties[$columnName])) {
                    $issues[] = $this->error($model, $columnName, 'Model 缺少对应的 @property 声明');
                }
            }

            foreach ($model->properties as $propertyName => $propertyType) {
                if (isset($model->relationProperties[$propertyName]) || ! $this->isColumnPropertyType($propertyType)) {
                    continue;
                }

                if (! isset($columns[$propertyName])) {
                    $issues[] = $this->error(
                        $model,
                        $propertyName,
                        sprintf('@property $%s 在数据库中不存在对应列（陈旧注解）', $propertyName)
                    );
                }
            }

            foreach ($model->fillable as $columnName) {
                if (! isset($columns[$columnName])) {
                    $issues[] = $this->error($model, $columnName, '$fillable 引用了不存在的列');
                }
            }

            foreach ($model->casts as $columnName => $cast) {
                if (! isset($columns[$columnName])) {
                    $issues[] = $this->error($model, $columnName, sprintf('$casts 引用了不存在的列（%s）', $cast));

                    continue;
                }

                if ($this->incompatibleCast($cast, $columns[$columnName]->type)) {
                    $issues[] = $this->error(
                        $model,
                        $columnName,
                        sprintf('$casts 的 %s 转换与列类型 %s 不匹配', $cast, $columns[$columnName]->type)
                    );
                }
            }

            $issues = array_merge($issues, $this->softDeleteIssues($model, $columns));
        }

        return $issues;
    }

    private function error(ModelMetadata $model, ?string $column, string $message): SchemaModelIssue
    {
        return new SchemaModelIssue(SchemaModelIssue::LEVEL_ERROR, $model->modelClass, $model->table, $column, $message);
    }

    private function warning(ModelMetadata $model, ?string $column, string $message): SchemaModelIssue
    {
        return new SchemaModelIssue(SchemaModelIssue::LEVEL_WARNING, $model->modelClass, $model->table, $column, $message);
    }

    /**
     * @param array<string, SchemaColumn> $columns
     * @return list<SchemaModelIssue>
     */
    private function softDeleteIssues(ModelMetadata $model, array $columns): array
    {
        $hasDeleteColumn = isset($columns['delete_time']);

        if ($model->usesSoftDeletes) {
            if (! $hasDeleteColumn) {
                return [$this->error($model, 'delete_time', '使用了 SoftDeletes 但数据库表没有 delete_time 列')];
            }

            if ($model->deletedAtColumn !== 'delete_time') {
                return [$this->error(
                    $model,
                    $model->deletedAtColumn,
                    sprintf('SoftDeletes 的软删除列是 %s，项目约定应为 delete_time', (string) $model->deletedAtColumn)
                )];
            }

            return [];
        }

        if ($hasDeleteColumn) {
            return [$this->error($model, 'delete_time', '数据库表存在 delete_time 列，但 Model 未使用 SoftDeletes')];
        }

        return [];
    }

    /**
     * 判断注解类型是否描述数据库列；关系属性（如 `Message`、`null|PublishBatch`）返回 false。
     */
    private function isColumnPropertyType(string $type): bool
    {
        $parts = array_filter(
            array_map('trim', explode('|', $type)),
            static fn (string $part): bool => $part !== '' && strtolower($part) !== 'null'
        );

        if ($parts === []) {
            return false;
        }

        foreach ($parts as $part) {
            if (str_contains($part, '<')) {
                continue;
            }

            if (! in_array(strtolower($part), self::SCALAR_PROPERTY_TYPES, true)) {
                return false;
            }
        }

        return true;
    }

    private function incompatibleCast(string $cast, string $columnType): bool
    {
        $normalized = strtolower(trim($cast));
        if (str_starts_with($normalized, 'encrypted')) {
            $normalized = explode(':', $normalized, 2)[1] ?? 'string';
        }

        $allowed = self::CAST_COLUMN_TYPES[explode(':', $normalized, 2)[0]] ?? null;
        if ($allowed === null) {
            return false;
        }

        return ! in_array(strtolower($columnType), $allowed, true);
    }
}
