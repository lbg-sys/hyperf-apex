<?php

declare(strict_types=1);

namespace Hyperf\Apex\RateLimit;

use Hyperf\Apex\Http\IpRuleMatcher;

/**
 * 客户端 IP 白名单：内网地址、监控探针等完全豁免限流。
 *
 * 规则支持单地址、CIDR 与通配 `*`，匹配语义复用 IpRuleMatcher。
 */
final class RateLimitWhitelist
{
    /** @param list<string> $rules */
    public function __construct(
        private readonly IpRuleMatcher $matcher,
        private readonly array $rules = [],
    ) {
    }

    /**
     * 取不到客户端 IP 时不算命中白名单：那是要告警的异常，不是免检。
     */
    public function contains(?string $ip): bool
    {
        if ($ip === null || $ip === '' || $this->rules === []) {
            return false;
        }

        foreach ($this->rules as $rule) {
            if ($this->matcher->matches($ip, $rule)) {
                return true;
            }
        }

        return false;
    }
}
