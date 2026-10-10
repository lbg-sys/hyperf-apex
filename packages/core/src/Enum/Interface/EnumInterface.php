<?php

declare(strict_types=1);

namespace Hyperf\Apex\Enum\Interface;

use BackedEnum;
use Hyperf\Contract\Arrayable;
use JsonSerializable;

/**
 * @extends Arrayable<string, int|string>
 */
interface EnumInterface extends JsonSerializable, BackedEnum, Arrayable
{
    /**
     * @param null|array<string, mixed> $replace
     */
    public function getMessage(?array $replace = null): string;

    public function equals(EnumInterface|int|string $value): bool;

    public static function isValidValue(EnumInterface|int|string $value): bool;

    public static function transform(EnumInterface|int|string|null $value): ?static;

    /**
     * @return list<array<string, int|string>>
     */
    public static function getOptions(string $key = 'key', string $name = 'name'): array;

    /**
     * @deprecated use getOptions() instead
     * @return list<array<string, int|string>>
     */
    public static function getOption(string $key = 'key', string $name = 'name'): array;
}
