<?php

declare(strict_types=1);

namespace Hyperf\Apex\Database\Schema;

/**
 * 单个 Model 的元数据快照，用于和数据库 schema 做一致性比对。
 *
 * `properties` 里有列属性和关系属性两类：关系属性（如 `@property null|AlertEvent $alert`）
 * 由 `relationProperties` 单独标出，校验时跳过，否则会被误判成「数据库里没有的列」。
 */
final readonly class ModelMetadata
{
    /**
     * @param class-string $modelClass
     * @param array<string, string> $properties `@property` 声明的属性：属性名 => 注解类型
     * @param array<string, true> $relationProperties 属于关系而非数据库列的属性名
     * @param array<string, string> $casts `$casts` 声明：列名 => 转换类型
     * @param list<string> $fillable `$fillable` 声明
     */
    public function __construct(
        public string $modelClass,
        public string $connection,
        public string $table,
        public array $properties,
        public array $relationProperties,
        public array $casts,
        public array $fillable,
        public bool $usesSoftDeletes,
        public ?string $deletedAtColumn,
    ) {
    }
}
