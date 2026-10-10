<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security;

use BackedEnum;
use Hyperf\Apex\Contract\PermissionInterface;

/**
 * 已解析的权限集合。
 *
 * 用于高效判定主体（Principal）是否持有特定权限。
 */
final readonly class PermissionSet
{
    /** @var array<string, true> */
    private array $index;

    /** @param list<string> $codes */
    public function __construct(array $codes)
    {
        $index = [];
        foreach ($codes as $code) {
            $index[$code] = true;
        }
        $this->index = $index;
    }

    /**
     * 判定是否拥有指定权限。
     */
    public function has(BackedEnum|PermissionInterface|string $code): bool
    {
        $key = match (true) {
            $code instanceof PermissionInterface => $code->identifier(),
            $code instanceof BackedEnum => (string) $code->value,
            default => (string) $code,
        };

        return isset($this->index[$key]);
    }

    /**
     * 判定是否拥有指定的所有权限（AND）。
     *
     * @param list<BackedEnum|PermissionInterface|string> $codes
     */
    public function hasAll(array $codes): bool
    {
        foreach ($codes as $code) {
            if (! $this->has($code)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 判定是否拥有指定的任意一个权限（OR）。
     *
     * @param list<BackedEnum|PermissionInterface|string> $codes
     */
    public function hasAny(array $codes): bool
    {
        foreach ($codes as $code) {
            if ($this->has($code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取所有权限标识列表。
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->index);
    }

    /**
     * 权限总数。
     */
    public function count(): int
    {
        return count($this->index);
    }
}
