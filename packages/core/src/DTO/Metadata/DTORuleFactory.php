<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Metadata;

use Hyperf\Apex\Validation\Rule\BooleanValueRule;
use Hyperf\Apex\Validation\Rule\NotBooleanRule;
use ReflectionNamedType;

final class DTORuleFactory
{
    /** @var array<class-string, array<string, list<object|string>>> */
    private static array $cache = [];

    /**
     * 取一个 DTO 的校验规则。
     *
     * 规则只由类结构决定、与请求无关，所以按类缓存（与 {@see DTOMetadataFactory} 同理）：
     * 这个方法在 HTTP 请求与 WS 每条消息的热路径上，之前每次都要重新遍历属性并对每个
     * Attribute 做一次反射实例化。
     *
     * @return array<string, list<object|string>>
     */
    public static function from(string $class): array
    {
        if (isset(self::$cache[$class])) {
            return self::cloneRules(self::$cache[$class]);
        }

        return self::cloneRules(self::$cache[$class] = self::rulesForClass($class));
    }

    /**
     * 浅克隆缓存里的规则对象，不把它们直接交出去。
     *
     * 规则里含对象（布尔、枚举、in/notIn 这类只能用对象表达的规则），其中
     * {@see BooleanValueRule} 等实现了 `ValidatorAwareRule`：
     * 校验过程中会 `setValidator()` 写入自身状态。跨协程共享同一个实例就是两个请求互相覆写状态 ——
     * 克隆的代价（实测约 2µs）远小于这类只在并发下才现形的错误。
     *
     * 只在字段确实含规则对象时才写回该字段，字符串规则与数组规则按值语义本来就无需处理，
     * 这样纯字符串规则的 DTO 几乎不为克隆付出代价。
     *
     * @param array<string, list<object|string>> $rules
     * @return array<string, list<object|string>>
     */
    private static function cloneRules(array $rules): array
    {
        foreach ($rules as $name => $fieldRules) {
            foreach ($fieldRules as $index => $rule) {
                if (is_object($rule)) {
                    $rules[$name][$index] = clone $rule;
                }
            }
        }

        return $rules;
    }

    /** @return array<string, list<object|string>> */
    private static function rulesForClass(string $class, string $prefix = ''): array
    {
        $rules = [];
        foreach (DTOMetadataFactory::from($class)->properties as $propertyName => $property) {
            $name = $prefix . $propertyName;
            // required 会拒绝 null；必传可空字段用 present 才能表达「键必须存在、值允许为空」。
            $fieldRules = [$property->required ? ($property->nullable ? 'present' : 'required') : 'sometimes'];
            $type = $property->type;
            if ($type instanceof ReflectionNamedType) {
                array_push($fieldRules, ...self::typeRule($type->getName()));
                if ($property->nullable) {
                    $fieldRules[] = 'nullable';
                }
            }
            foreach ($property->validationAttributes as $attribute) {
                $instance = $attribute->newInstance();
                array_push($fieldRules, ...$instance->validationRules());
            }
            $rules[$name] = $fieldRules;
            if ($property->itemType !== null) {
                $key = $name . '.*';
                $itemRules = self::itemRules($key, $property->itemType);
                foreach ($property->itemAttributes as $attribute) {
                    $instance = $attribute->newInstance();
                    array_push($itemRules[$key], ...$instance->itemValidationRules());
                }
                $rules += $itemRules;
            }
        }
        return $rules;
    }

    /**
     * 元素规则按类型表达式的深度展开：list<string> 同时产出 field.* 与 field.*.*。
     *
     * 这里必须用 parseOrLeaf()：本方法在 HTTP 请求热路径上执行，
     * 不认识的类型写法要退化成今天的兜底行为，而不是抛异常变成 500。
     *
     * @return array<string, list<object|string>>
     */
    private static function itemRules(string $key, TypeExpression $expression): array
    {
        if (! $expression->isLeaf()) {
            return [$key => ['array']] + self::itemRules($key . '.*', $expression->item);
        }

        $itemType = $expression->name;
        $rules = [$key => class_exists($itemType) ? ['array'] : self::typeRule($itemType)];

        return class_exists($itemType) ? $rules + self::rulesForClass($itemType, $key . '.') : $rules;
    }

    /**
     * 标量类型 → 校验规则。返回列表是为了让 int 能在保留 integer 的同时追加一条补充判据。
     *
     * bool 返回规则对象而不是 'boolean' 字符串：Hyperf 的 boolean 用严格比较，
     * GET query / form 传来的 "true"、"false" 会被拒（POST JSON 的真布尔没问题），
     * 与文档和水合的语义都不一致。详见 BooleanValueRule。
     *
     * int 必须保留 'integer' 字符串本身：Hyperf 判断「该字段是不是数值规则」靠的是规则名
     * （Validator::$numericRules），把它整体换成规则对象会让 min/max 从数值比较退化成
     * 字符串长度比较。保留之后再追加 NotBooleanRule，堵住「布尔被 filter_var 字符串化成 1
     * 放行、水合却按 is_numeric 落成 0」的错位。详见 NotBooleanRule。
     *
     * @return list<object|string>
     */
    private static function typeRule(string $type): array
    {
        return match ($type) {
            'int', 'integer' => ['integer', new NotBooleanRule()],
            'string' => ['string'],
            'bool', 'boolean' => [new BooleanValueRule()],
            'float', 'number', 'numeric' => ['numeric'],
            'array' => ['array'],
            default => ['array'],
        };
    }
}
