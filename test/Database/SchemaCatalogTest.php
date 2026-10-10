<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Database;

use Hyperf\Apex\Database\Schema\SchemaCatalog;
use Hyperf\Apex\Database\Schema\SchemaColumn;
use PHPUnit\Framework\TestCase;

/**
 * 单连接的 schema 快照。
 *
 * `information_schema` 返回的是带连接前缀的物理表名，而校验逻辑一律传 Model 的逻辑表名，
 * 前缀拼接收敛在本类里 —— 拼错的话所有表都会被判成「不存在」。
 */
final class SchemaCatalogTest extends TestCase
{
    public function testPrefixIsAppliedToPhysicalTableNames(): void
    {
        $catalog = new SchemaCatalog('core_', ['core_users' => '用户表'], ['core_users' => []]);

        $this->assertTrue($catalog->hasTable('users'));
        $this->assertFalse($catalog->hasTable('orders'));
    }

    public function testTableCommentAndColumnsAreReturned(): void
    {
        $column = new SchemaColumn('id', 'bigint', '主键');
        $catalog = new SchemaCatalog('', ['users' => '用户表'], ['users' => ['id' => $column]]);

        $this->assertSame('用户表', $catalog->tableComment('users'));
        $this->assertSame(['id' => $column], $catalog->columns('users'));
    }

    public function testMissingTableReturnsEmptyDefaults(): void
    {
        $catalog = new SchemaCatalog('', [], []);

        $this->assertSame('', $catalog->tableComment('users'));
        $this->assertSame([], $catalog->columns('users'));
    }
}
