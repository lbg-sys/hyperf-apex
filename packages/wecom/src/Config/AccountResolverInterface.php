<?php

declare(strict_types=1);

namespace Hyperf\Apex\WeCom\Config;

use Hyperf\Apex\WeCom\DTO\WeComAccountDTO;

/**
 * 企业微信账号（一个 corp_id 对应一个企业）的配置来源。
 *
 * 默认实现是 {@see ConfigAccountResolver}（读 `config/autoload/wecom.php`）。
 * 企业配置要能在后台改、或者一个进程要按租户动态取不同企业时，
 * 实现本接口并在 ConfigProvider 里替换绑定即可 —— 组件其余部分只依赖这个契约。
 *
 * 两个方法都必须无副作用且足够快：它们在每次取客户端时都会被调用，允许内部缓存。
 */
interface AccountResolverInterface
{
    /**
     * 按 alias 取企业配置；alias 未配置（或 corp_id 为空）时返回 null。
     *
     * 返回 null 表示「这个企业不可用」，调用方据此抛 503 而不是继续用一个空配置去发请求：
     * 空配置发出去的请求会被企微判成参数错误，报错点离配置很远。
     */
    public function resolve(string $alias): ?WeComAccountDTO;

    /**
     * 调用方未指定 alias 时使用的企业。
     */
    public function defaultAlias(): string;
}
