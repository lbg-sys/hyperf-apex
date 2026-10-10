<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Support;

use Hyperf\Apex\Support\LikePattern;
use PHPUnit\Framework\TestCase;

/**
 * LIKE 模式串构造的契约。
 *
 * 用户输入里的 `%`、`_`、`\` 是 LIKE 元字符，不转义会让 `%` 退化成匹配所有行
 * （等于把关键字筛选悄悄取消）、`_` 退化成任意单字符。
 *
 * 断言的期望值用 chr(92) 表达反斜杠：手写一堆 `\` 的字面量太容易数错。
 */
final class LikePatternTest extends TestCase
{
    private const BS = "\x5C"; // 反斜杠

    public function testContainsWrapsWithWildcards(): void
    {
        $this->assertSame('%alice%', LikePattern::contains('alice'));
    }

    public function testStartsWithEscapesAndAppendsWildcard(): void
    {
        $this->assertSame('100' . self::BS . '%%', LikePattern::startsWith('100%'));
    }

    public function testEscapeHandlesEachMetacharacter(): void
    {
        $this->assertSame(self::BS . '%', LikePattern::escape('%'));
        $this->assertSame(self::BS . '_', LikePattern::escape('_'));
        $this->assertSame(self::BS . self::BS, LikePattern::escape(self::BS));
    }

    public function testBackslashIsEscapedBeforePercentAndUnderscore(): void
    {
        // 反斜杠必须先替换：否则会把后面两步新加的反斜杠再转义一遍，结果多出一倍的 `\`。
        // 输入 `\%`：先把 `\` 换成 `\\`，再把 `%` 换成 `\%`，最终是三个反斜杠 + %。
        $this->assertSame(self::BS . self::BS . self::BS . '%', LikePattern::escape(self::BS . '%'));
    }

    public function testEscapeHandlesAllMetacharactersTogether(): void
    {
        $this->assertSame(
            'a' . self::BS . '%b' . self::BS . '_c' . self::BS . self::BS . 'd',
            LikePattern::escape('a%b_c' . self::BS . 'd'),
        );
    }
}
