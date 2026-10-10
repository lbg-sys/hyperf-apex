<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Support;

use Hyperf\Apex\Support\SqlBindingRedactor;
use PHPUnit\Framework\TestCase;

/**
 * SQL 日志绑定值脱敏的契约。
 *
 * 只遮敏感列的绑定，其余照常展开 —— 否则为了遮一个字段把整条 SQL 的参数都丢掉，
 * 日志也就失去了排查价值。敏感列判定复用 SensitiveDataFilter 的同一份口径。
 */
final class SqlBindingRedactorTest extends TestCase
{
    public function testEmptyBindingsReturnSqlUnchanged(): void
    {
        $this->assertSame('SELECT 1', SqlBindingRedactor::interpolate('SELECT 1', []));
    }

    public function testSensitiveAssignmentIsRedacted(): void
    {
        $result = SqlBindingRedactor::interpolate(
            'UPDATE users SET password_hash = ?, name = ?',
            ['secret123', 'alice'],
        );

        $this->assertSame("UPDATE users SET password_hash = '[redacted]', name = 'alice'", $result);
    }

    public function testSensitiveInListIsRedacted(): void
    {
        $result = SqlBindingRedactor::interpolate(
            'SELECT * FROM sessions WHERE lease_token IN (?, ?)',
            ['a', 'b'],
        );

        $this->assertSame("SELECT * FROM sessions WHERE lease_token IN ('[redacted]', '[redacted]')", $result);
    }

    public function testInsertValuesAlignByColumnOrder(): void
    {
        $result = SqlBindingRedactor::interpolate(
            'INSERT INTO users (name, password_hash) VALUES (?, ?)',
            ['alice', 'secret'],
        );

        $this->assertSame("INSERT INTO users (name, password_hash) VALUES ('alice', '[redacted]')", $result);
    }

    public function testNonSensitiveBindingsFullyExpand(): void
    {
        // 非敏感绑定照常展开，整条 SQL 可读 —— 不然遮一个字段把全部参数都丢掉，日志就没用了。
        $result = SqlBindingRedactor::interpolate(
            'SELECT * FROM users WHERE id = ? AND status = ?',
            [5, 'active'],
        );

        $this->assertSame("SELECT * FROM users WHERE id = '5' AND status = 'active'", $result);
    }
}
