<?php

declare(strict_types=1);

namespace Hyperf\Apex\Enum\Traits;

use Hyperf\Apex\Enum\Interface\EnumInterface;
use Hyperf\Constants\Annotation\Message;
use Hyperf\Constants\EnumConstantsTrait;
use LogicException;
use ReflectionEnum;
use Throwable;
use UnexpectedValueException;

/**
 * @mixin EnumInterface
 */
trait EnumTrait
{
    use EnumConstantsTrait;

    /**
     * @return list<array<string, int|string>>
     */
    public static function getOptions(string $key = 'key', string $name = 'name'): array
    {
        $data = [];
        foreach (static::cases() as $case) {
            $data[] = [
                $key => $case->value,
                $name => $case->getMessage(),
            ];
        }

        return $data;
    }

    /**
     * @deprecated use getOptions() instead
     * @return list<array<string, int|string>>
     */
    public static function getOption(string $key = 'key', string $name = 'name'): array
    {
        return static::getOptions($key, $name);
    }

    public static function isValidValue(EnumInterface|int|string $value): bool
    {
        return static::transform($value) !== null;
    }

    public static function transform(EnumInterface|int|string|null $value): ?static
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof static) {
            return $value;
        }

        if ($value instanceof EnumInterface) {
            return null;
        }

        if (self::getBackingType() === 'int') {
            if (is_string($value)) {
                $normalized = filter_var($value, FILTER_VALIDATE_INT);
                if ($normalized === false) {
                    return null;
                }
                $value = $normalized;
            }
        } elseif (is_int($value)) {
            $value = (string) $value;
        }

        return static::tryFrom($value);
    }

    /**
     * @param null|array<string, mixed> $replace
     */
    public function getMessage(?array $replace = null): string
    {
        try {
            $message = $this->__call('getMessage', $replace === null ? [] : [$replace]);
            if (is_string($message) && $message !== '') {
                return $message;
            }
        } catch (Throwable) {
            // Hyperf constants metadata is unavailable before annotation scanning.
        }

        $case = (new ReflectionEnum(static::class))->getCase($this->name);
        $attribute = $case->getAttributes(Message::class)[0] ?? null;
        if ($attribute === null) {
            throw new UnexpectedValueException(sprintf(
                'Enum message is not configured for %s::%s.',
                static::class,
                $this->name
            ));
        }

        $message = $attribute->newInstance()->value;
        return $replace === null ? $message : sprintf($message, ...$replace);
    }

    /**
     * @return array{key: int|string, name: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return array{key: int|string, name: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->value,
            'name' => $this->getMessage(),
        ];
    }

    public function equals(EnumInterface|int|string $value): bool
    {
        return $this === static::transform($value);
    }

    /**
     * @return 'int'|'string'
     */
    private static function getBackingType(): string
    {
        /** @var array<class-string, 'int'|'string'> $cache */
        static $cache = [];

        if (isset($cache[static::class])) {
            return $cache[static::class];
        }

        $type = (new ReflectionEnum(static::class))->getBackingType();
        if ($type === null) {
            throw new LogicException(sprintf('%s must be a backed enum.', static::class));
        }

        $name = $type->getName();
        if ($name !== 'int' && $name !== 'string') {
            throw new LogicException(sprintf('Unsupported enum backing type: %s.', $name));
        }

        return $cache[static::class] = $name;
    }
}
