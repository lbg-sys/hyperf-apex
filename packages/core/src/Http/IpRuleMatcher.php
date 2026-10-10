<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http;

/**
 * 判断一个 IP 是否命中某条匹配规则（单地址 / CIDR / 通配 `*`）。
 *
 * 可信代理判定与限流白名单用的是同一套语义，各写一份位运算迟早会在「非 8 整数倍
 * 前缀」「v4-mapped 地址」这类边界上漏掉一处，所以收敛到这里共用。
 *
 * 规则串不做任何网络访问，纯粹是字面量比较：非法规则与非法地址一律判为不匹配。
 */
final class IpRuleMatcher
{
    /** ::ffff:a.b.c.d 的前 12 字节，用于把 IPv4 与 IPv6 对齐到同一地址族后比较。 */
    private const MAPPED_IPV4_PREFIX = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";

    public function matches(string $ip, string $rule): bool
    {
        $rule = trim($rule);
        if ($rule === '*') {
            return true;
        }

        $ipBinary = $this->pack($ip);
        if ($ipBinary === null) {
            return false;
        }

        if (! str_contains($rule, '/')) {
            $ruleBinary = $this->pack($rule);

            return $ruleBinary !== null && $this->expand($ipBinary) === $this->expand($ruleBinary);
        }

        return $this->matchesCidr($ipBinary, $rule);
    }

    private function matchesCidr(string $ipBinary, string $rule): bool
    {
        [$subnet, $prefix] = explode('/', $rule, 2);
        $prefix = trim($prefix);
        if ($prefix === '' || filter_var($prefix, FILTER_VALIDATE_INT) === false) {
            return false;
        }

        $subnetBinary = $this->pack(trim($subnet));
        if ($subnetBinary === null) {
            return false;
        }

        if (strlen($subnetBinary) !== strlen($ipBinary)) {
            // 只有「IPv6 网段 + IPv4 地址」这一种跨族情况能对齐；反过来说地址族不同，不可能落在同一网段。
            if (strlen($subnetBinary) !== 16 || strlen($ipBinary) !== 4) {
                return false;
            }
            $ipBinary = self::MAPPED_IPV4_PREFIX . $ipBinary;
        }

        $bits = (int) $prefix;
        $maxBits = strlen($ipBinary) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }

        $wholeBytes = intdiv($bits, 8);
        if ($wholeBytes > 0 && strncmp($subnetBinary, $ipBinary, $wholeBytes) !== 0) {
            return false;
        }

        $remainingBits = $bits % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($subnetBinary[$wholeBytes]) & $mask) === (ord($ipBinary[$wholeBytes]) & $mask);
    }

    /**
     * 规整成可比较的二进制地址，非法字面量返回 null。
     *
     * v4-mapped 的 IPv6 会折叠回 4 字节，否则 ::ffff:10.0.0.1 与 10.0.0.1 会被判成两个地址。
     */
    private function pack(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        // 不用 `@` 抑制符：filter_var 已校验过，inet_pton() 对非法字面量返回 false 而不发告警。
        $binary = inet_pton($ip);
        if ($binary === false) {
            return null;
        }

        return strlen($binary) === 16 && str_starts_with($binary, self::MAPPED_IPV4_PREFIX)
            ? substr($binary, 12)
            : $binary;
    }

    /**
     * 展开成 v4-mapped 形式，使两种地址族能直接做字符串比较。
     */
    private function expand(string $binary): string
    {
        return strlen($binary) === 4 ? self::MAPPED_IPV4_PREFIX . $binary : $binary;
    }
}
