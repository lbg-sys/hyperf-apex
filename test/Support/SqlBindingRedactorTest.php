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

        // 数字与布尔不再套引号：`id = '5'` 看起来像字符串列，读日志的人会误判列的形态。
        $this->assertSame("SELECT * FROM users WHERE id = 5 AND status = 'active'", $result);
    }

    public function testQuestionMarkInsideStringLiteralIsNotAPlaceholder(): void
    {
        // SQL 里的 `?` 不都是占位符：字面量里那个若被当成占位符，后面的绑定会整体错位 ——
        // 不仅填错位置，敏感值判定也跟着错位，本该遮住的密码会以明文落进日志。
        $result = SqlBindingRedactor::interpolate(
            "UPDATE users SET password_hash = ?, remark = '有?号' WHERE id = ?",
            ['secret-hash', 7],
        );

        $this->assertSame("UPDATE users SET password_hash = '[redacted]', remark = '有?号' WHERE id = 7", $result);
    }

    public function testBindingValuesAreEscaped(): void
    {
        // 不转义不只是可读性问题：含换行的值能在日志里伪造出完整的假行（log injection）。
        $result = SqlBindingRedactor::interpolate(
            'SELECT * FROM users WHERE name = ?',
            ["O'Brien\nDROP TABLE users"],
        );

        $this->assertStringContainsString("O\\'Brien", $result);
        $this->assertStringContainsString('\n', $result);
        $this->assertStringNotContainsString("\n", $result);
    }

    public function testNullAndBooleanBindingsAreNotQuoted(): void
    {
        $result = SqlBindingRedactor::interpolate(
            'UPDATE users SET deleted_at = ?, enabled = ? WHERE id = ?',
            [null, true, 3],
        );

        $this->assertSame('UPDATE users SET deleted_at = NULL, enabled = 1 WHERE id = 3', $result);
    }
}
