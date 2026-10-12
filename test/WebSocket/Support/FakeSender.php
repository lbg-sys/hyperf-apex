<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket\Support;

use Hyperf\WebSocketServer\Sender;

/**
 * Sender 替身。
 *
 * 父类用 __call 代理到 Swoole\Server，单测里没有 server 实例，所以这里定义同名真实方法把它拦下来：
 * PHP 只有在方法不存在时才会走 __call，子类定义了 push() / disconnect() 就轮不到父类。
 * 构造函数被覆盖成空实现，正是为了绕开「必须注入容器」这件事。
 *
 * 两个返回值都要能摆布：它们的行为差异（协程 Server 恒真/恒假、传统 Server 恒真）
 * 正是推送与回收两条路径上的分支依据。
 */
final class FakeSender extends Sender
{
    /** @var list<array{0: int, 1: string}> */
    public array $pushed = [];

    /** @var list<int> */
    public array $disconnected = [];

    public bool $pushResult = true;

    public bool $disconnectResult = true;

    public function __construct()
    {
    }

    public function push(int $fd, mixed $data, ?int $opcode = null, mixed $finish = null): bool
    {
        $this->pushed[] = [$fd, (string) $data];

        return $this->pushResult;
    }

    public function disconnect(int $fd, ?int $code = null, ?string $reason = null): bool
    {
        $this->disconnected[] = $fd;

        return $this->disconnectResult;
    }
}
