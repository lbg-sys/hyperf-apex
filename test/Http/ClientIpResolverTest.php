<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Http;

use Hyperf\Apex\Http\ClientIpResolver;
use Hyperf\Apex\Http\IpRuleMatcher;
use Hyperf\Config\Config;
use Hyperf\HttpMessage\Server\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 客户端真实 IP 解析的信任边界。
 *
 * 转发头是普通请求头、客户端可随手伪造，无条件采信等于让任何人自选记进审计日志的 IP。
 * 唯一不可伪造的是 remote_addr，所以只在「连接确实来自可信代理」时才读转发头。
 */
final class ClientIpResolverTest extends TestCase
{
    public function testNoTrustedProxiesReturnsRemoteAddress(): void
    {
        $resolver = $this->resolver([]);

        $result = $resolver->resolve($this->request('10.0.0.5', ['X-Forwarded-For' => '1.2.3.4']));

        $this->assertSame('10.0.0.5', $result);
    }

    public function testUntrustedRemoteIgnoresForwardedHeader(): void
    {
        // remote 不在可信代理里，即使带了转发头也不采信。
        $resolver = $this->resolver(['10.0.0.1']);

        $result = $resolver->resolve($this->request('10.0.0.99', ['X-Forwarded-For' => '1.2.3.4']));

        $this->assertSame('10.0.0.99', $result);
    }

    public function testTrustedProxyReadsForwardedChainSkippingProxiesFromTheRight(): void
    {
        // 链最右端是最近的代理，向左跳过可信代理，取第一个不可信地址作为客户端。
        $resolver = $this->resolver(['10.0.0.1']);

        $result = $resolver->resolve($this->request('10.0.0.1', ['X-Forwarded-For' => '1.2.3.4, 10.0.0.1']));

        $this->assertSame('1.2.3.4', $result);
    }

    public function testAllTrustedChainReturnsLeftmost(): void
    {
        $resolver = $this->resolver(['10.0.0.0/8']);

        $result = $resolver->resolve($this->request('10.0.0.1', ['X-Forwarded-For' => '10.1.0.1, 10.2.0.1']));

        $this->assertSame('10.1.0.1', $result);
    }

    public function testIpv4WithPortIsNormalized(): void
    {
        $this->assertSame('10.0.0.5', $this->resolver([])->resolve($this->request('10.0.0.5:8080')));
    }

    public function testV4MappedIpv6IsCollapsedToIpv4(): void
    {
        $this->assertSame('10.0.0.5', $this->resolver([])->resolve($this->request('::ffff:10.0.0.5')));
    }

    public function testMissingRemoteAddressReturnsNull(): void
    {
        $this->assertNull($this->resolver([])->resolve($this->request(null)));
    }

    /**
     * @param list<string> $trustedProxies
     */
    private function resolver(array $trustedProxies): ClientIpResolver
    {
        return new ClientIpResolver(new Config(['client_ip' => ['trusted_proxies' => $trustedProxies]]), new IpRuleMatcher());
    }

    /**
     * @param array<string, string> $forwarded
     */
    private function request(?string $remoteAddr, array $forwarded = []): ServerRequestInterface
    {
        $request = new Request('GET', '/x');
        if ($remoteAddr !== null) {
            $request = $request->withServerParams(['remote_addr' => $remoteAddr]);
        }
        foreach ($forwarded as $header => $value) {
            $request = $request->withHeader($header, $value);
        }

        return $request;
    }
}
