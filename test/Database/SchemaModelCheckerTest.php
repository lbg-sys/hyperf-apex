<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Database;

use Hyperf\Apex\Database\Schema\ModelMetadata;
use Hyperf\Apex\Database\Schema\SchemaCatalog;
use Hyperf\Apex\Database\Schema\SchemaColumn;
use Hyperf\Apex\Database\Schema\SchemaModelChecker;
use Hyperf\Apex\Database\Schema\SchemaModelIssue;
use PHPUnit\Framework\TestCase;

/**
 * Model 元数据与数据库 schema 的一致性比对。
 *
 * 这是「把漂移拦在启动期」的检查器：注解/配置与真实列对不上就报 error（运行期会出错），
 * 违反项目约定（时间字段不以 _time 结尾）报 warning。输入是两份快照，不连库，
 * 所以每条规则都能用构造的数据覆盖。
 */
final class SchemaModelCheckerTest extends TestCase
{
    private SchemaModelChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new SchemaModelChecker();
    }

    public function testConsistentModelProducesNoIssues(): void
    {
        $this->assertSame([], $this->checker->check([$this->model()], ['default' => $this->catalog()]));
    }

    public function testMissingCatalogSnapshot(): void
    {
        $issues = $this->checker->check([$this->model()], []);

        $this->assertStringContainsString('缺少连接 default 的 schema 快照', $this->messages($issues));
    }

    public function testMissingTable(): void
    {
        // hasTable 同时看列与注释，两处都要清空才代表「表不存在」。
        $issues = $this->checker->check([$this->model()], ['default' => $this->catalog(['tableColumns' => [], 'tableComments' => []])]);

        $this->assertStringContainsString('数据库表 users 不存在', $this->messages($issues));
    }

    public function testTableMissingComment(): void
    {
        $issues = $this->checker->check([$this->model()], ['default' => $this->catalog(['tableComments' => ['users' => '']])]);

        $this->assertStringContainsString('数据表缺少中文注释', $this->messages($issues));
    }

    public function testColumnMissingComment(): void
    {
        $catalog = $this->catalog([
            'tableColumns' => ['users' => ['id' => new SchemaColumn('id', 'bigint', '')]],
        ]);

        $issues = $this->checker->check([$this->model()], ['default' => $catalog]);

        $this->assertStringContainsString('字段缺少中文注释', $this->messages($issues));
    }

    public function testUppercaseColumnTypeIsStillChecked(): void
    {
        // 快照若直接给了大写类型（DATETIME / BIGINT / VARCHAR），时间命名约定与 cast 校验仍要生效，
        // 不能因为大小写就静默失效。
        $catalog = $this->catalog([
            'tableColumns' => ['users' => [
                'id' => new SchemaColumn('id', 'BIGINT', '主键'),
                'name' => new SchemaColumn('name', 'VARCHAR', '姓名'),
                'updated_at' => new SchemaColumn('updated_at', 'DATETIME', '更新时间'),
            ]],
        ]);

        $issues = $this->checker->check([$this->model()], ['default' => $catalog]);

        $this->assertStringContainsString('时间字段命名应以 _time 结尾', $this->messages($issues));
        // BIGINT 对 int cast 仍兼容，不应误报「转换与列类型不匹配」。
        $this->assertStringNotContainsString('转换与列类型', $this->messages($issues));
    }

    public function testTimeColumnNamingConvention(): void
    {
        $catalog = $this->catalog([
            'tableColumns' => ['users' => [
                'id' => new SchemaColumn('id', 'bigint', '主键'),
                'updated_at' => new SchemaColumn('updated_at', 'datetime', '更新时间'),
            ]],
        ]);

        $issues = $this->checker->check([$this->model()], ['default' => $catalog]);

        $this->assertStringContainsString('时间字段命名应以 _time 结尾', $this->messages($issues));
    }

    public function testColumnWithoutPropertyDeclaration(): void
    {
        $catalog = $this->catalog([
            'tableColumns' => ['users' => [
                'id' => new SchemaColumn('id', 'bigint', '主键'),
                'status' => new SchemaColumn('status', 'varchar', '状态'),
            ]],
        ]);

        $issues = $this->checker->check([$this->model()], ['default' => $catalog]);

        $this->assertStringContainsString('Model 缺少对应的 @property 声明', $this->messages($issues));
    }

    public function testStalePropertyWithoutColumn(): void
    {
        $model = $this->model(['properties' => ['id' => 'int', 'stale' => 'string']]);

        $issues = $this->checker->check([$model], ['default' => $this->catalog()]);

        $this->assertStringContainsString('@property $stale 在数据库中不存在对应列（陈旧注解）', $this->messages($issues));
    }

    public function testRelationAndNonColumnPropertiesAreSkipped(): void
    {
        // 关系属性与非标量注解类型（如大写开头的类）都不代表数据库列，不该被误报成陈旧注解。
        $model = $this->model([
            'properties' => ['id' => 'int', 'name' => 'string', 'alert' => 'AlertEvent', 'items' => 'HasMany'],
            'relationProperties' => ['items' => true],
        ]);

        $this->assertSame([], $this->checker->check([$model], ['default' => $this->catalog()]));
    }

    public function testFillableReferencingMissingColumn(): void
    {
        $model = $this->model(['fillable' => ['name', 'ghost']]);

        $issues = $this->checker->check([$model], ['default' => $this->catalog()]);

        $this->assertStringContainsString('$fillable 引用了不存在的列', $this->messages($issues));
    }

    public function testCastReferencingMissingColumn(): void
    {
        $model = $this->model(['casts' => ['id' => 'int', 'ghost' => 'string']]);

        $issues = $this->checker->check([$model], ['default' => $this->catalog()]);

        $this->assertStringContainsString('$casts 引用了不存在的列（string）', $this->messages($issues));
    }

    public function testIncompatibleCast(): void
    {
        $model = $this->model(['casts' => ['id' => 'string']]);

        $issues = $this->checker->check([$model], ['default' => $this->catalog()]);

        $this->assertStringContainsString('$casts 的 string 转换与列类型 bigint 不匹配', $this->messages($issues));
    }

    public function testSoftDeletesWithoutDeleteTimeColumn(): void
    {
        $model = $this->model(['usesSoftDeletes' => true, 'deletedAtColumn' => 'delete_time']);

        $issues = $this->checker->check([$model], ['default' => $this->catalog()]);

        $this->assertStringContainsString('使用了 SoftDeletes 但数据库表没有 delete_time 列', $this->messages($issues));
    }

    public function testSoftDeletesWithWrongDeletedAtColumn(): void
    {
        $catalog = $this->catalog([
            'tableColumns' => ['users' => [
                'id' => new SchemaColumn('id', 'bigint', '主键'),
                'name' => new SchemaColumn('name', 'varchar', '姓名'),
                'delete_time' => new SchemaColumn('delete_time', 'datetime', '删除时间'),
            ]],
        ]);
        $model = $this->model(['usesSoftDeletes' => true, 'deletedAtColumn' => 'deleted_at']);

        $issues = $this->checker->check([$model], ['default' => $catalog]);

        $this->assertStringContainsString('项目约定应为 delete_time', $this->messages($issues));
    }

    public function testDeleteTimeColumnWithoutSoftDeletes(): void
    {
        $catalog = $this->catalog([
            'tableColumns' => ['users' => [
                'id' => new SchemaColumn('id', 'bigint', '主键'),
                'name' => new SchemaColumn('name', 'varchar', '姓名'),
                'delete_time' => new SchemaColumn('delete_time', 'datetime', '删除时间'),
            ]],
        ]);

        $issues = $this->checker->check([$this->model()], ['default' => $catalog]);

        $this->assertStringContainsString('数据库表存在 delete_time 列，但 Model 未使用 SoftDeletes', $this->messages($issues));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function model(array $overrides = []): ModelMetadata
    {
        $defaults = [
            'modelClass' => 'App\Model\User',
            'connection' => 'default',
            'table' => 'users',
            'properties' => ['id' => 'int', 'name' => 'string'],
            'relationProperties' => [],
            'casts' => ['id' => 'int'],
            'fillable' => ['name'],
            'usesSoftDeletes' => false,
            'deletedAtColumn' => null,
        ];

        return new ModelMetadata(...array_merge($defaults, $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function catalog(array $overrides = []): SchemaCatalog
    {
        $defaults = [
            'prefix' => '',
            'tableComments' => ['users' => '用户表'],
            'tableColumns' => ['users' => [
                'id' => new SchemaColumn('id', 'bigint', '主键'),
                'name' => new SchemaColumn('name', 'varchar', '姓名'),
            ]],
        ];

        return new SchemaCatalog(...array_merge($defaults, $overrides));
    }

    /**
     * @param list<SchemaModelIssue> $issues
     */
    private function messages(array $issues): string
    {
        return implode("\n", array_map(static fn (SchemaModelIssue $issue): string => $issue->message, $issues));
    }
}
