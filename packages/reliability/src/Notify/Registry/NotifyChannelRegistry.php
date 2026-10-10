<?php

declare(strict_types=1);

namespace Hyperf\Apex\Notify\Registry;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Notify\Contract\NotifyChannelInterface;
use Hyperf\Apex\Notify\Contract\NotifyChannelRegistryInterface;
use Hyperf\Apex\Notify\DTO\NotifyChannelOptionDTO;
use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;

/**
 * 通知渠道注册表。
 *
 * 与 `HandleRegistry` 同构：读配置里的类列表、
 * 逐个问 `code()` 建索引。渠道注册不走 `match` 硬编码——
 * 那意味着每加一个渠道都要改一次调度层的构造函数。
 *
 * 刻意不在实例内缓存解析结果：Hyperf 的容器会把已解析的实例缓存下来（`Container::get()`），
 * 这里的 `get()` 拿到的本来就是 worker 内单例，再缓存一层只是把同一份引用存两遍。
 *
 * 也正因为渠道是单例，**不要给渠道构造注入持有请求态的协作者** —— 那是跨协程共享，会在并发下
 * 互相覆写状态（同一个坑见 `LlmSseParser` 改为在协议驱动里每次 `new`）。渠道数只有个位数，
 * 与 `HandleRegistry` 一样每次解析即可。
 */
final class NotifyChannelRegistry implements NotifyChannelRegistryInterface
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ConfigInterface $config,
    ) {
    }

    public function get(string $code): NotifyChannelInterface
    {
        $channel = $this->channels()[$code] ?? null;
        if ($channel === null) {
            throw new BusinessException(ErrorCode::INVALID_ARGUMENT, sprintf('通知渠道 %s 未注册', $code));
        }

        return $channel;
    }

    public function has(string $code): bool
    {
        return isset($this->channels()[$code]);
    }

    /** @return list<NotifyChannelOptionDTO> */
    public function options(): array
    {
        return array_map(
            static fn (NotifyChannelInterface $channel): NotifyChannelOptionDTO => new NotifyChannelOptionDTO(
                $channel->code(),
                $channel->name()
            ),
            array_values($this->channels())
        );
    }

    /** @return array<string, NotifyChannelInterface> */
    private function channels(): array
    {
        $resolved = [];
        foreach ($this->channelClasses() as $class) {
            $channel = $this->container->get($class);
            /*
             * 类型守卫把「配置里写错了一个类名」从「调用 code() 时抛
             * Call to undefined method」变成一条能看懂的异常。
             * 这种错误只会在投递时才暴露，模糊的报错会让排查成本高得多。
             */
            if (! $channel instanceof NotifyChannelInterface) {
                throw new BusinessException(
                    ErrorCode::INTERNAL_ERROR,
                    sprintf('通知渠道 %s 必须实现 %s', $class, NotifyChannelInterface::class)
                );
            }

            $resolved[$channel->code()] = $channel;
        }

        return $resolved;
    }

    /** @return list<class-string<NotifyChannelInterface>> */
    private function channelClasses(): array
    {
        $classes = $this->config->get('notify.channels', []);

        return is_array($classes) ? array_values(array_filter($classes, 'is_string')) : [];
    }
}
