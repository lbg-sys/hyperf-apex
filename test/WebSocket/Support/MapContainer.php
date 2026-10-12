<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\WebSocket\Support;

use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * 只按类名取实例的容器替身：业务事件派发只用到这一个能力。
 *
 * 与 FakePrincipalContext 同理，放在 Support 里以便多个用例文件共用。
 */
final class MapContainer implements ContainerInterface
{
    /** @param array<string, object> $map */
    public function __construct(private readonly array $map)
    {
    }

    public function get(string $id): mixed
    {
        return $this->map[$id] ?? throw new RuntimeException(sprintf('容器里没有 %s。', $id));
    }

    public function has(string $id): bool
    {
        return isset($this->map[$id]);
    }
}
