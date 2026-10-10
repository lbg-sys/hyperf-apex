<?php

declare(strict_types=1);

namespace Hyperf\Apex\Notify\Contract;

use Hyperf\Apex\Notify\DTO\NotifyChannelOptionDTO;

interface NotifyChannelRegistryInterface
{
    /**
     * 按渠道编码取适配器。
     *
     * 编码未注册时抛业务异常——调用方拿不到渠道实现就无法投递，
     * 静默返回 null 只会把「渠道没注册」变成更难定位的空指针。
     */
    public function get(string $code): NotifyChannelInterface;

    public function has(string $code): bool;

    /** @return list<NotifyChannelOptionDTO> */
    public function options(): array;
}
