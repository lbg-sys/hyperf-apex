<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket\Support;

use Hyperf\Apex\Security\Contract\PrincipalContextInterface;
use Hyperf\Apex\Security\Principal;
use RuntimeException;

/**
 * 身份上下文替身：记录 set / clear 次数。
 *
 * 放在 Support 而不是某个用例文件里，是因为多个用例文件都要用它 —— 写在用例文件里的类
 * 只能靠「PHPUnit 先加载了那个文件」才存在，换个执行顺序就会 Class not found。
 */
final class FakePrincipalContext implements PrincipalContextInterface
{
    public int $setCalls = 0;

    public int $clearCalls = 0;

    private ?Principal $principal = null;

    public function set(Principal $principal): void
    {
        ++$this->setCalls;
        $this->principal = $principal;
    }

    public function get(): Principal
    {
        return $this->principal ?? throw new RuntimeException('当前协程没有身份。');
    }

    public function clear(): void
    {
        ++$this->clearCalls;
        $this->principal = null;
    }
}
