<?php

declare(strict_types=1);

namespace Hyperf\Apex\Database\Schema;

/**
 * 一条 Schema 与 Model 不一致的检查结果。
 *
 * error 表示会导致运行期行为错误的漂移（注解/配置与真实列对不上），
 * warning 表示尚未造成故障、但违反项目约定的写法（例如时间字段未以 `_time` 结尾）。
 */
final readonly class SchemaModelIssue
{
    public const LEVEL_ERROR = 'error';

    public const LEVEL_WARNING = 'warning';

    public function __construct(
        public string $level,
        public string $model,
        public string $table,
        public ?string $column,
        public string $message,
    ) {
    }

    public function isError(): bool
    {
        return $this->level === self::LEVEL_ERROR;
    }
}
