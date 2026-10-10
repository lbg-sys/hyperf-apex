<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Schema;

use InvalidArgumentException;

/**
 * OpenAPI Schema 片段的值对象，是 Attribute 层与文档编译层之间唯一的跨界类型。
 *
 * 设计要点：
 * - 内部是「关键字 => 值」的映射，天然区分三态：键不存在=未设置、键存在且为 null=显式 null、
 *   键存在且有值=有值。因此 `default: null`、`const: null`、`example: null` 都能正确表达，
 *   不需要额外的哨兵常量。
 * - 关键字名与 OpenAPI 3.1 Schema Object 一致，编译层可直接映射，不做二次翻译。
 * - 刻意不实现 JsonSerializable：万一某个片段漏了展开、残留在 schema 树里，
 *   json_encode 必须直接报错，而不是静默写出结构错误的文档。
 */
final class SchemaFragment
{
    /**
     * OpenAPI 3.1 / JSON Schema 2020-12 允许的关键字白名单。
     * 拼错的关键字会立刻抛异常，而不是静默产出无效文档。
     */
    private const KEYWORDS = [
        // 标识与引用
        'schema', 'title', 'description', 'ref', 'type', 'format', 'nullable',
        // 字符串
        'minLength', 'maxLength', 'pattern', 'contentMediaType', 'contentEncoding',
        // 数值
        'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
        // 数组
        'items', 'minItems', 'maxItems', 'uniqueItems', 'prefixItems',
        'contains', 'minContains', 'maxContains', 'unevaluatedItems',
        // 对象
        'properties', 'required', 'additionalProperties', 'patternProperties',
        'minProperties', 'maxProperties', 'unevaluatedProperties', 'propertyNames',
        'dependentRequired', 'dependentSchemas',
        // 组合与条件
        'allOf', 'anyOf', 'oneOf', 'not', 'if', 'then', 'else',
        // 取值
        'enum', 'const', 'example', 'examples', 'default',
        // 元信息
        'deprecated', 'readOnly', 'writeOnly', 'discriminator', 'externalDocs', 'xml',
        // 厂商扩展：值为 array<string, mixed>，编译时展开成 x-{key}
        'x',
    ];

    /** @param array<string, mixed> $keywords */
    private function __construct(private readonly array $keywords)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param array<string, mixed> $keywords
     */
    public static function of(array $keywords): self
    {
        foreach (array_keys($keywords) as $keyword) {
            if (! in_array($keyword, self::KEYWORDS, true)) {
                throw new InvalidArgumentException(sprintf('未知的 Schema 关键字 %s。', (string) $keyword));
            }
        }

        return new self($keywords);
    }

    /**
     * 过滤掉值为 null 的关键字，用于「未指定就不输出」的可选约束（如 Length 的 min/max）。
     * 需要表达显式 null 时请用 of()。
     *
     * @param array<string, mixed> $keywords
     */
    public static function ofPresent(array $keywords): self
    {
        return self::of(array_filter($keywords, static fn (mixed $value): bool => $value !== null));
    }

    public function with(string $keyword, mixed $value): self
    {
        return self::of(array_replace($this->keywords, [$keyword => $value]));
    }

    /**
     * 浅覆盖合并：$other 的关键字覆盖当前值。
     *
     * 两个特例：
     * - description 追加而非覆盖（已有的在前，换行连接），保证 #[EnumValue] 的可选值说明
     *   不会吃掉 #[FieldDescription] 的字段含义；
     * - x 厂商扩展合并内层键，避免多个 Attribute 各自贡献扩展时互相覆盖。
     *
     * 必须保持「浅」覆盖：#[MapOf] 依赖它把类型推断出的 type: array 盖成 type: object，
     * 改成深合并或「已存在的键不覆盖」都会静默退化。
     */
    public function merge(self $other): self
    {
        $addition = $other->keywords;

        if (isset($this->keywords['description'], $addition['description'])) {
            $addition['description'] = $this->keywords['description'] . "\n" . $addition['description'];
        }

        if (isset($this->keywords['x'], $addition['x']) && is_array($this->keywords['x']) && is_array($addition['x'])) {
            $addition['x'] = array_replace($this->keywords['x'], $addition['x']);
        }

        return new self(array_replace($this->keywords, $addition));
    }

    public function has(string $keyword): bool
    {
        return array_key_exists($keyword, $this->keywords);
    }

    /**
     * 注意区分「键不存在」与「值为 null」：前者返回 $default，后者返回 null。
     */
    public function get(string $keyword, mixed $default = null): mixed
    {
        return array_key_exists($keyword, $this->keywords) ? $this->keywords[$keyword] : $default;
    }

    public function isEmpty(): bool
    {
        return $this->keywords === [];
    }

    /**
     * 仅供编译层读取。业务代码不应直接消费这个数组。
     *
     * @return array<string, mixed>
     */
    public function keywords(): array
    {
        return $this->keywords;
    }
}
