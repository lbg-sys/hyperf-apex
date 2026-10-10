<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO;

use Hyperf\Apex\DTO\Attribute\IntAsString;
use Hyperf\Contract\Arrayable;
use JsonException;
use ReflectionClass;

trait DTOJsonableTrait
{
    public function __toString(): string
    {
        return $this->toJson();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = get_object_vars($this);

        // #[IntAsString] 标注的属性在这里出站成十进制字符串。转换放在 normalize() 之上一层
        // 而不是内层：normalize() 对嵌套 DTO 调的是它自己的 toArray()，所以每个 DTO 各自
        // 处理自己的标注，递归天然正确；放进 normalize() 就得把「属性属于哪个类」一路传下去。
        foreach (self::intAsStringProperties(static::class) as $name) {
            // isset 而非 array_key_exists：可空 int 的 null 语义是「没有值」，转成空串会把它
            // 变成「有值且为空」，前端拿到的是两种不同的东西。
            if (isset($data[$name])) {
                $data[$name] = (string) $data[$name];
            }
        }

        return self::normalize($data);
    }

    /**
     * 让 json_encode() 与 toJson() 走同一条路径。
     *
     * 不实现这个接口时，json_encode($dto) 会绕开 toArray() 直接序列化公开属性，
     * #[IntAsString] 这类出站转换静默失效 —— 同一个对象两种编码方式产出不同结果。
     * 枚举早就是这么做的（EnumInterface extends JsonSerializable, Arrayable），理由一样：
     * 既要能被 json_encode 直接处理，也要能被上层递归展开。
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(int $options = 0): string
    {
        try {
            return json_encode(
                $this->toArray(),
                $options | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw $exception;
        }
    }

    /**
     * @template TKey of array-key
     * @param array<TKey, mixed> $data
     * @return array<TKey, mixed>
     */
    private static function normalize(array $data): array
    {
        return array_map(static fn (mixed $value): mixed => self::normalizeValue($value), $data);
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof Arrayable) {
            return self::normalize($value->toArray());
        }

        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::normalizeValue($item), $value);
        }

        return $value;
    }

    /**
     * 标注了 #[IntAsString] 的属性名。
     *
     * 按类静态缓存：toArray() 在每次响应序列化都会跑，不能每次反射。没有标注的类拿到空数组，
     * 后续只剩一次静态数组查找。
     *
     * @param class-string $class
     * @return list<string>
     */
    private static function intAsStringProperties(string $class): array
    {
        /** @var array<class-string, list<string>> $cache */
        static $cache = [];

        if (isset($cache[$class])) {
            return $cache[$class];
        }

        $names = [];
        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            if ($property->getAttributes(IntAsString::class) !== []) {
                $names[] = $property->getName();
            }
        }

        return $cache[$class] = $names;
    }
}
