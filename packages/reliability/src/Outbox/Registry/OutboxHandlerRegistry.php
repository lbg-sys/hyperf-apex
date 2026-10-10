<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbox\Registry;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Outbox\Contract\OutboxHandlerInterface;
use Hyperf\Apex\Outbox\Contract\OutboxHandlerRegistryInterface;
use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;

/**
 * 事件类型 → handler 的注册表，读 `outbox.handlers` 配置。
 *
 * 与 `NotifyChannelRegistry` 同构：不在实例内缓存解析结果 ——
 * handler 由容器持有（worker 内单例），缓存只是把同一份引用存两遍；
 * 事件类型只有个位数，每次解析的代价可以忽略。
 *
 * 同类型注册多个 handler 直接报错，不做「先注册者生效」的静默遮蔽：
 * 那会让另一份 handler 永远不被调用，而配置看起来完全正常。
 */
final class OutboxHandlerRegistry implements OutboxHandlerRegistryInterface
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ConfigInterface $config,
    ) {
    }

    public function find(string $eventType): ?OutboxHandlerInterface
    {
        return $this->handlers()[$eventType] ?? null;
    }

    /** @return array<string, OutboxHandlerInterface> */
    private function handlers(): array
    {
        $classes = $this->config->get('outbox.handlers', []);
        if (! is_array($classes)) {
            return [];
        }

        $resolved = [];
        foreach (array_values(array_filter($classes, 'is_string')) as $class) {
            $handler = $this->container->get($class);
            /*
             * 类型守卫把「配置里写错一个类名」从「调用 eventType() 时抛 undefined method」
             * 变成一条能看懂的异常 —— 这种错误只在投递时才暴露，模糊报错会让排查成本高得多。
             */
            if (! $handler instanceof OutboxHandlerInterface) {
                throw new BusinessException(
                    ErrorCode::INTERNAL_ERROR,
                    sprintf('Outbox handler %s 必须实现 %s', $class, OutboxHandlerInterface::class)
                );
            }

            $eventType = $handler->eventType();
            if (isset($resolved[$eventType])) {
                throw new BusinessException(
                    ErrorCode::INTERNAL_ERROR,
                    sprintf('事件类型 %s 注册了多个 handler：%s 与 %s', $eventType, $resolved[$eventType]::class, $class)
                );
            }

            $resolved[$eventType] = $handler;
        }

        return $resolved;
    }
}
