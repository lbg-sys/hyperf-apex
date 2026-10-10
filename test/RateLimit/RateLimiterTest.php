<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\RateLimit;

use Hyperf\Apex\Http\ClientIpResolver;
use Hyperf\Apex\Http\IpRuleMatcher;
use Hyperf\Apex\RateLimit\RateLimitScopes;
use Hyperf\Apex\RateLimit\Resolver\ClientIpIdentityResolver;
use Hyperf\Apex\RateLimit\Resolver\GlobalIdentityResolver;
use Hyperf\Config\Config;
use Hyperf\HttpMessage\Server\Request;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    public function testGlobalIdentityResolverReturnsStaticKey(): void
    {
        $resolver = new GlobalIdentityResolver();
        $this->assertSame(RateLimitScopes::GLOBAL, $resolver->scope());

        $request = new Request('GET', '/api/test');
        $this->assertSame('all', $resolver->resolve($request));
    }

    public function testClientIpIdentityResolverExtractsIp(): void
    {
        $config = new Config([
            'client_ip' => [
                'trusted_proxies' => ['10.0.0.1'],
            ],
        ]);
        $clientIpResolver = new ClientIpResolver($config, new IpRuleMatcher());
        $resolver = new ClientIpIdentityResolver($clientIpResolver);
        $this->assertSame(RateLimitScopes::IP, $resolver->scope());

        $request = (new Request('GET', '/api/test'))
            ->withServerParams(['remote_addr' => '10.0.0.1'])
            ->withHeader('x-forwarded-for', '203.0.113.195, 10.0.0.1');

        $this->assertSame('203.0.113.195', $resolver->resolve($request));
    }
}
