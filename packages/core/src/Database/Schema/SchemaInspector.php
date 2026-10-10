<?php

declare(strict_types=1);

namespace Hyperf\Apex\Database\Schema;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Schema\Builder;
use Hyperf\Database\Schema\Column;
use Hyperf\DbConnection\Connection;
use RuntimeException;

/**
 * 从数据库读取 schema 快照。
 *
 * 只在开发期与 CI 使用：`getColumns()` 会把整库的列一次性捞回来，属于重查询，
 * 不能出现在生产请求路径上。
 */
final class SchemaInspector
{
    public function __construct(
        private readonly ConnectionResolverInterface $resolver,
        private readonly ConfigInterface $config,
    ) {
    }

    public function catalog(string $connection): SchemaCatalog
    {
        $resolved = $this->resolver->connection($connection);
        if (! $resolved instanceof Connection) {
            throw new RuntimeException(sprintf('连接 %s 不是连接池连接，无法读取 schema', $connection));
        }

        // 连接池的 Connection 通过 __call 转发到具体连接，PHPStan 看不到 getSchemaBuilder()。
        /** @var Builder $builder */
        $builder = $resolved->getSchemaBuilder(); // @phpstan-ignore-line method.notFound

        $comments = [];
        foreach ($builder->getTables() as $table) {
            if (! is_array($table) || ! isset($table['name'])) {
                continue;
            }

            $comments[(string) $table['name']] = isset($table['comment']) ? (string) $table['comment'] : '';
        }

        $columns = [];
        foreach ($builder->getColumns() as $column) {
            if (! $column instanceof Column) {
                continue;
            }

            $columns[$column->getTable()][$column->getName()] = new SchemaColumn(
                $column->getName(),
                strtolower($column->getType()),
                $column->getComment(),
            );
        }

        return new SchemaCatalog($this->prefix($connection), $comments, $columns);
    }

    private function prefix(string $connection): string
    {
        return (string) $this->config->get('databases.' . $connection . '.prefix', '');
    }
}
