<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http;

use Hyperf\Contract\ConfigInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 解析客户端真实 IP。
 *
 * 接入 Nginx、SLB 等反向代理后，`remote_addr` 拿到的是代理自身的地址，真实来源
 * 在 `X-Forwarded-For` 一类转发头里。但转发头是普通请求头，客户端可以随手伪造，
 * 无条件采信等于让任何人自选记进审计日志的 IP —— 比记成代理 IP 更糟。
 *
 * 所以这里以唯一不可伪造的 `remote_addr` 为信任起点：仅当连接确实来自
 * `client_ip.trusted_proxies` 时才读转发头，并从链条右端向左跳过可信代理，
 * 取第一个不可信地址作为客户端。未配置可信代理时行为与直连部署完全一致。
 */
final class ClientIpResolver
{
    private const DEFAULT_HEADERS = ['X-Forwarded-For', 'X-Real-IP'];

    /** @var null|list<string> 缓存：由纯配置派生，运行期不变 */
    private ?array $trustedProxies = null;

    /** @var null|list<string> */
    private ?array $forwardedHeaders = null;

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly IpRuleMatcher $rules,
    ) {
    }

    /**
     * 取请求的客户端 IP，无法确定时返回 null。
     */
    public function resolve(?ServerRequestInterface $request): ?string
    {
        if ($request === null) {
            return null;
        }

        $remoteAddr = $this->normalize($request->getServerParams()['remote_addr'] ?? null);
        $trustedProxies = $this->trustedProxies();

        // 连接方本身不可信，转发头一律不采信。
        if ($trustedProxies === [] || $remoteAddr === null || ! $this->isTrusted($remoteAddr, $trustedProxies)) {
            return $remoteAddr;
        }

        foreach ($this->forwardedHeaders() as $header) {
            $chain = $this->parseChain($request->getHeaderLine($header));
            if ($chain === []) {
                continue;
            }

            // 从右向左跳过可信代理，第一个不可信地址即客户端。
            for ($i = count($chain) - 1; $i >= 0; --$i) {
                if (! $this->isTrusted($chain[$i], $trustedProxies)) {
                    return $chain[$i];
                }
            }

            // 整条链都可信（通配或纯内网链路），最左端就是客户端。
            return $chain[0];
        }

        return $remoteAddr;
    }

    /**
     * @return list<string>
     */
    private function trustedProxies(): array
    {
        return $this->trustedProxies ??= $this->stringList('client_ip.trusted_proxies', []);
    }

    /**
     * @return list<string>
     */
    private function forwardedHeaders(): array
    {
        return $this->forwardedHeaders ??= $this->stringList('client_ip.forwarded_headers', self::DEFAULT_HEADERS);
    }

    /**
     * 读配置里的字符串列表，去空白、去空项，空列表回落到默认值。
     *
     * 结果由纯配置派生、运行期不变，而 resolve() 在每请求（乃至每条日志）都会被调，
     * 所以缓存一次，避免每回都重读配置、重建数组。
     *
     * @param list<string> $default
     * @return list<string>
     */
    private function stringList(string $key, array $default): array
    {
        $configured = $this->config->get($key, $default);
        if (! is_array($configured)) {
            return $default;
        }

        $list = array_values(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', $configured),
            static fn (string $item): bool => $item !== ''
        ));

        return $list === [] ? $default : $list;
    }

    /**
     * 拆分逗号分隔的转发头，丢弃无法解析的条目。
     *
     * @return list<string>
     */
    private function parseChain(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        $chain = [];
        foreach (explode(',', $value) as $candidate) {
            $normalized = $this->normalize($candidate);
            if ($normalized !== null) {
                $chain[] = $normalized;
            }
        }

        return $chain;
    }

    /**
     * 规整成可比较的 IP 字面量，非法值返回 null。
     */
    private function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $ip = trim($value);
        if ($ip === '') {
            return null;
        }

        // `[::1]:8080` 与 `[::1]` 形式的 IPv6。
        if (str_starts_with($ip, '[')) {
            $end = strpos($ip, ']');
            $ip = $end === false ? ltrim($ip, '[') : substr($ip, 1, $end - 1);
        } elseif (substr_count($ip, ':') === 1) {
            // 只有一个冒号必然是 IPv4:port —— 裸 IPv6 至少两个冒号。
            $ip = explode(':', $ip, 2)[0];
        }

        // 去掉 `fe80::1%eth0` 的 zone id。
        $zone = strpos($ip, '%');
        if ($zone !== false) {
            $ip = substr($ip, 0, $zone);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $this->collapseMappedIpv4($ip);
    }

    /**
     * 把 `::ffff:127.0.0.1` 折叠回 IPv4。
     *
     * 双栈监听下 IPv4 客户端可能以 v4-mapped 形式出现，不折叠就会与按 IPv4
     * 网段写的可信规则匹配不上。
     */
    private function collapseMappedIpv4(string $ip): string
    {
        if (! str_contains($ip, ':')) {
            return $ip;
        }

        /*
         * 没有 `@` 抑制符：入参已由 normalize() 的 filter_var 校验过，而 inet_pton() 对非法
         * 字面量是「返回 false」而不是「发告警」，抑制符在这里无事可抑制，留着只会让人误以为
         * 这个调用需要特殊对待。返回值判断保留——它是不变式万一被破坏时的兜底。
         */
        $binary = inet_pton($ip);
        if ($binary === false || strlen($binary) !== 16) {
            return $ip;
        }

        $mappedPrefix = str_repeat("\x00", 10) . "\xff\xff";
        if (! str_starts_with($binary, $mappedPrefix)) {
            return $ip;
        }

        // 上面已确认前缀是 12 字节且总长 16，substr 取到的正好是 4 字节，inet_ntop() 不会失败。
        $ipv4 = inet_ntop(substr($binary, 12));
        return $ipv4 === false ? $ip : $ipv4;
    }

    /**
     * @param list<string> $trustedProxies
     */
    private function isTrusted(string $ip, array $trustedProxies): bool
    {
        foreach ($trustedProxies as $rule) {
            if ($this->rules->matches($ip, $rule)) {
                return true;
            }
        }

        return false;
    }
}
