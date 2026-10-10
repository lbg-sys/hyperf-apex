<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Metrics;

use Hyperf\Apex\Http\ClientIpResolver;
use Hyperf\Apex\Http\IpRuleMatcher;
use Hyperf\Apex\Metrics\Access\MetricsAccessGuard;
use Hyperf\Config\Config;
use Hyperf\HttpMessage\Server\Request;
use PHPUnit\Framework\TestCase;

final class MetricsAccessGuardTest extends TestCase
{
    public function testDefaultWhitelistAllowsLoopbackOnly(): void
    {
        $guard = $this->guard(['127.0.0.1', '::1']);

        $this->assertTrue($guard->allows($this->request('127.0.0.1')));
        $this->assertFalse($guard->allows($this->request('10.0.0.5')));
    }

    public function testCidrRuleMatchesSubnet(): void
    {
        $guard = $this->guard(['10.1.0.0/16']);

        $this->assertTrue($guard->allows($this->request('10.1.2.3')));
        $this->assertFalse($guard->allows($this->request('10.2.2.3')));
    }

    public function testEmptyWhitelistSkipsTheCheck(): void
    {
        // 空名单是配置注释里写明的「不校验」，留给已经由网关限住来源的部署。
        $guard = $this->guard([]);

        $this->assertTrue($guard->allows($this->request('203.0.113.9')));
    }

    public function testRequestWithoutRemoteAddressIsRejectedWhenWhitelistConfigured(): void
    {
        // 拿不到来源地址就不能放行：白名单配了却判不出来源，等于门禁失效。
        $this->assertFalse($this->guard(['127.0.0.1'])->allows($this->request(null)));
    }

    /** @param list<string> $allowIps */
    private function guard(array $allowIps): MetricsAccessGuard
    {
        $config = new Config(['metrics' => ['allow_ips' => $allowIps]]);

        return new MetricsAccessGuard($config, new ClientIpResolver($config, new IpRuleMatcher()), new IpRuleMatcher());
    }

    private function request(?string $remoteAddr): Request
    {
        $request = new Request('GET', '/metrics');
        if ($remoteAddr === null) {
            return $request;
        }

        return $request->withServerParams(['remote_addr' => $remoteAddr]);
    }
}
