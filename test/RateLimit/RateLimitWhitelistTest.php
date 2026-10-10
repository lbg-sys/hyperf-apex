<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\RateLimit;

use Hyperf\Apex\Http\IpRuleMatcher;
use Hyperf\Apex\RateLimit\RateLimitWhitelist;
use PHPUnit\Framework\TestCase;

/**
 * 限流白名单的语义。
 *
 * 白名单是「完全豁免」：内网地址、监控探针不应被限流。取不到 IP 不算命中 —— 那是要告警的
 * 异常，不是免检；配置为空也不豁免（空的默认值不该悄悄放行全部流量）。
 */
final class RateLimitWhitelistTest extends TestCase
{
    public function testMissingIpIsNotWhitelisted(): void
    {
        // 取不到 IP 是异常，不是免检。
        $whitelist = new RateLimitWhitelist(new IpRuleMatcher(), ['127.0.0.1']);

        $this->assertFalse($whitelist->contains(null));
        $this->assertFalse($whitelist->contains(''));
    }

    public function testEmptyRulesMatchNothing(): void
    {
        // 空名单不豁免任何地址：空的默认值不该悄悄放行全部流量。
        $this->assertFalse((new RateLimitWhitelist(new IpRuleMatcher()))->contains('127.0.0.1'));
    }

    public function testExactIpAndCidrMatch(): void
    {
        $whitelist = new RateLimitWhitelist(new IpRuleMatcher(), ['10.1.2.3', '10.0.0.0/8']);

        $this->assertTrue($whitelist->contains('10.1.2.3'));
        $this->assertTrue($whitelist->contains('10.9.8.7'));
        $this->assertFalse($whitelist->contains('192.168.1.1'));
        $this->assertFalse($whitelist->contains('203.0.113.9'));
    }

    public function testWildcardMatchesEverything(): void
    {
        $this->assertTrue((new RateLimitWhitelist(new IpRuleMatcher(), ['*']))->contains('203.0.113.9'));
    }
}
