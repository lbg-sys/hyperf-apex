<?php

declare(strict_types=1);

namespace Hyperf\Apex\Database\Command;

use Hyperf\Apex\Database\Schema\ModelMetadataReader;
use Hyperf\Apex\Database\Schema\SchemaInspector;
use Hyperf\Apex\Database\Schema\SchemaModelChecker;
use Hyperf\Apex\Database\Schema\SchemaModelIssue;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * 开发期 / CI 检查：数据库 schema 与 Model 元数据是否一致。
 *
 * 迁移改了列名或类型，而 Model 的 `@property`、`$casts`、`$fillable` 没跟着改，
 * 静态分析看不出来（注解与配置都在，只是和真实列对不上），要到运行期才以
 * 「属性读不到 / 写入被静默丢弃 / cast 出错」的形式暴露。本命令把这类漂移提前到构建期。
 *
 * 需要已执行迁移的数据库：它读的是 `information_schema`，不是迁移文件。
 * 属于重查询，只在本地与 CI 跑，不进生产请求路径。
 */
#[Command(name: 'schema:model-check', description: '检查数据库 Schema 与 Model 元数据（@property / $casts / $fillable / 注释 / 软删除）是否一致，供开发与 CI 使用。')]
final class SchemaModelCheckCommand extends HyperfCommand
{
    public function __construct(
        private readonly ModelMetadataReader $reader,
        private readonly SchemaInspector $inspector,
        private readonly SchemaModelChecker $checker,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $models = $this->reader->read();
        if ($models === []) {
            $this->error('未发现任何 Model，请确认扫描路径是否正确。');

            return self::FAILURE;
        }

        $catalogs = [];
        foreach ($models as $model) {
            $catalogs[$model->connection] ??= $this->inspector->catalog($model->connection);
        }

        $issues = $this->checker->check($models, $catalogs);
        $this->render($issues, count($models));

        $errors = array_filter($issues, static fn (SchemaModelIssue $issue): bool => $issue->isError());
        $warnings = array_filter($issues, static fn (SchemaModelIssue $issue): bool => ! $issue->isError());

        if ($errors !== []) {
            return self::FAILURE;
        }

        if ($warnings !== [] && (bool) $this->input->getOption('strict')) {
            $this->error('存在 warning 且指定了 --strict，按失败处理。');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function configure(): void
    {
        $this->addOption('strict', null, InputOption::VALUE_NONE, '把 warning 也视为失败（用于清空命名债后收紧 CI）');
    }

    /**
     * @param list<SchemaModelIssue> $issues
     */
    private function render(array $issues, int $modelCount): void
    {
        $grouped = [];
        foreach ($issues as $issue) {
            $grouped[$issue->model][] = $issue;
        }
        ksort($grouped);

        $errorCount = 0;
        $warningCount = 0;

        foreach ($grouped as $model => $modelIssues) {
            $this->line('');
            $this->line($model);

            foreach ($modelIssues as $issue) {
                $location = $issue->column === null ? $issue->table : $issue->table . '.' . $issue->column;
                $line = sprintf('  [%s] %s %s', $issue->level, $location, $issue->message);

                if ($issue->isError()) {
                    ++$errorCount;
                    $this->error($line);

                    continue;
                }

                ++$warningCount;
                $this->line($line);
            }
        }

        $this->line('');
        $this->line(sprintf('检查完成：%d 个模型，%d 个 error，%d 个 warning。', $modelCount, $errorCount, $warningCount));

        if ($errorCount === 0 && $warningCount === 0) {
            $this->info('数据库 Schema 与 Model 元数据一致。');
        }
    }
}
