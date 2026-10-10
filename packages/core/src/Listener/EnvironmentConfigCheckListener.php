<?php

declare(strict_types=1);

namespace Hyperf\Apex\Listener;

use Hyperf\Apex\Diagnostics\StartupChecker;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeMainServerStart;
use RuntimeException;

/**
 * 服务启动前的环境与配置 fail-fast 校验监听器。
 *
 * 在端口监听之前做一次性校验，缺什么一次性列清楚并拒绝启动，把配置错误从运行期故障提前到部署期暴露。
 */
final class EnvironmentConfigCheckListener implements ListenerInterface
{
    public function __construct(private readonly StartupChecker $checker)
    {
    }

    public function listen(): array
    {
        return [BeforeMainServerStart::class];
    }

    public function process(object $event): void
    {
        $errors = $this->checker->check();
        if ($errors === []) {
            return;
        }

        throw new RuntimeException(
            '环境配置校验失败，服务拒绝启动：' . "\n - " . implode("\n - ", $errors)
        );
    }
}
