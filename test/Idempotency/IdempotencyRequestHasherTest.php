<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Idempotency;

use Hyperf\Apex\Idempotency\IdempotencyRequestHasher;
use Hyperf\HttpMessage\Server\Request;
use PHPUnit\Framework\TestCase;

/**
 * 请求摘要的稳定性契约。
 *
 * 摘要决定「两个请求是不是同一个」，它的稳定性就是幂等判定的稳定性：
 * - 关联数组的键顺序不影响摘要（query / body 的子项递归排序）—— 客户端换个字段顺序不该失配；
 * - list 的元素顺序**是**语义，不参与排序 —— 改了顺序要能识别成不同请求；
 * - 顶层键保持 query、body 固定顺序，不参与排序（历史记录兼容）。
 */
final class IdempotencyRequestHasherTest extends TestCase
{
    public function testQueryKeyOrderDoesNotAffectTheHash(): void
    {
        $hasher = new IdempotencyRequestHasher();

        $a = $this->request(['a' => '1', 'b' => '2']);
        $b = $this->request(['b' => '2', 'a' => '1']);

        $this->assertSame($hasher->hash($a), $hasher->hash($b));
    }

    public function testBodyKeyOrderDoesNotAffectTheHash(): void
    {
        $hasher = new IdempotencyRequestHasher();

        $a = $this->request(query: [], body: ['x' => 1, 'y' => 2]);
        $b = $this->request(query: [], body: ['y' => 2, 'x' => 1]);

        $this->assertSame($hasher->hash($a), $hasher->hash($b));
    }

    public function testListOrderIsSignificant(): void
    {
        $hasher = new IdempotencyRequestHasher();

        $a = $this->request(body: ['tags' => ['x', 'y']]);
        $b = $this->request(body: ['tags' => ['y', 'x']]);

        // list 的元素顺序是语义的一部分，改了顺序必须识别成不同请求。
        $this->assertNotSame($hasher->hash($a), $hasher->hash($b));
    }

    public function testDifferentBodyProducesADifferentHash(): void
    {
        $hasher = new IdempotencyRequestHasher();

        $this->assertNotSame(
            $hasher->hash($this->request(body: ['amount' => 1])),
            $hasher->hash($this->request(body: ['amount' => 2])),
        );
    }

    /** @param array<string, mixed> $query */
    private function request(array $query = [], ?array $body = null): Request
    {
        $request = new Request('POST', '/fixture');
        $request = $request->withQueryParams($query);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $request;
    }
}
