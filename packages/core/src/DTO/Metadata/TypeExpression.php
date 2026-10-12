<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Metadata;

use InvalidArgumentException;

/**
 * 类型表达式的解析结果，用来在「元素类型」这个位置上表达嵌套结构。
 *
 * #[ArrayOf]、#[MapOf]、组合类 Attribute 的类型参数以及 @return 注释里的泛型写法
 * 都由这里统一解析，避免同一套语法在文档、校验、水合三处各写一份实现。
 *
 * 三个入口对应三种失败容忍度：
 * - parse()：文档构建期用，无法表达的写法直接抛异常，绝不静默降级成 type: string；
 * - tryParse()：调用方自己决定回落方式（@return 注释回落到 type: array）；
 * - parseOrLeaf()：HTTP 请求热路径用，不认识的写法退化成「按类型名原样处理」，永不抛异常。
 */
final readonly class TypeExpression
{
    public const KIND_LEAF = 'leaf';

    public const KIND_LIST = 'list';

    public const KIND_MAP = 'map';

    /** 可以承载元素类型的泛型基类名，全部映射到 JSON 的数组或对象。 */
    private const ARRAY_LIKE = ['list', 'array', 'iterable', 'non-empty-list', 'non-empty-array'];

    /** 只接受一个类型参数的基类名。 */
    private const SINGLE_ARGUMENT = ['list', 'non-empty-list'];

    private function __construct(
        public string $kind,
        public string $name,
        public ?self $item,
    ) {
    }

    public static function parse(string $expression): self
    {
        return self::tryParse($expression) ?? throw new InvalidArgumentException(self::unsupported($expression));
    }

    public static function tryParse(string $expression): ?self
    {
        $expression = trim($expression);
        if ($expression === '') {
            return null;
        }

        /*
         * 快速通道：不含泛型语法的类型名直接成叶子，既有写法在请求热路径上零解析成本。
         *
         * `X|Y` 与 `?X` 是 PHP 的可空 / 联合写法，类型表达式不支持（可空由属性的 PHP 类型表达）。
         * 在这里就判掉：否则 `?FooDTO` 会被当成类名一路走到 fragmentForLeaf，报出来的是
         * 「类请写完整类名（如 \App\Foo\DTO\BarDTO）」—— 与真实原因完全不符，写的人会去
         * 反复检查类名。`?Foo[]`、`list<?Foo>` 这类嵌套写法同样经由这里被判掉。
         */
        if (! str_contains($expression, '<') && ! str_contains($expression, '{') && ! str_ends_with($expression, '[]')) {
            return str_contains($expression, '|') || str_contains($expression, '?') ? null : self::leaf($expression);
        }

        // array{a: int} 形状无法归约成单一元素类型，交由调用方回落。
        if (str_contains($expression, '{')) {
            return null;
        }

        if (str_ends_with($expression, '[]')) {
            $item = self::tryParse(substr($expression, 0, -2));

            return $item === null ? null : self::listOf($item);
        }

        if (! preg_match('/^([A-Za-z_][A-Za-z0-9_-]*)<(.+)>$/s', $expression, $matches)) {
            return null;
        }

        return self::generic(strtolower($matches[1]), self::splitArguments($matches[2]));
    }

    public static function parseOrLeaf(string $expression): self
    {
        return self::tryParse($expression) ?? self::leaf(trim($expression));
    }

    public function isLeaf(): bool
    {
        return $this->kind === self::KIND_LEAF;
    }

    /**
     * 把每个叶子类型名过一遍 $resolve，用于将注释里的短类名还原成完整类名。
     *
     * @param callable(string): string $resolve
     */
    public function mapLeaves(callable $resolve): self
    {
        return $this->isLeaf()
            ? self::leaf($resolve($this->name))
            : new self($this->kind, '', $this->item?->mapLeaves($resolve));
    }

    /**
     * 解析失败时的中文提示。
     *
     * 先分辨「可空 / 联合写法」这类常见误写并点明原因：笼统地说「无法解析」时，写 `?FooDTO` 的
     * 人会去反复检查类名是否写全，而真正的问题是这个位置不支持可空与联合语法。
     */
    private static function unsupported(string $expression): string
    {
        $trimmed = trim($expression);
        if (str_contains($trimmed, '?') || str_contains($trimmed, '|')) {
            return sprintf(
                '类型表达式 %s 里的可空 / 联合写法不受支持：可空由属性的 PHP 类型（?X）表达，'
                . '联合请改用单一类型，或在文档里用 #[OneOf]。',
                $trimmed
            );
        }

        return sprintf(
            '无法解析的类型表达式 %s。支持 list<X>、array<X>、array<string, X>、X[] 及其任意嵌套；'
            . 'array{...} 形状、泛型参数内的联合类型和未知泛型基类请改用嵌套 DTO 表达。',
            $trimmed
        );
    }

    /**
     * @param list<string> $arguments
     */
    private static function generic(string $base, array $arguments): ?self
    {
        if (! in_array($base, self::ARRAY_LIKE, true)) {
            return null;
        }

        if (count($arguments) === 1) {
            $item = self::tryParse($arguments[0]);

            return $item === null ? null : self::listOf($item);
        }

        if (count($arguments) !== 2 || in_array($base, self::SINGLE_ARGUMENT, true)) {
            return null;
        }

        $item = self::tryParse($arguments[1]);
        if ($item === null) {
            return null;
        }

        // 键类型只影响 JSON 形态：int 键就是数组，其余按键名不固定的对象处理。
        return in_array(strtolower(trim($arguments[0])), ['int', 'integer'], true) ? self::listOf($item) : self::mapOf($item);
    }

    /**
     * 按 <> 深度切分泛型参数：array<string, array<string, int>> 用 explode(',') 会被切错。
     *
     * @return list<string>
     */
    private static function splitArguments(string $arguments): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        for ($index = 0, $length = strlen($arguments); $index < $length; ++$index) {
            $character = $arguments[$index];
            if ($character === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }

            $depth += match ($character) {
                '<' => 1,
                '>' => -1,
                default => 0,
            };
            $current .= $character;
        }

        $parts[] = trim($current);

        return $parts;
    }

    private static function leaf(string $name): self
    {
        // 去掉前导反斜线：'\App\Foo\BarDTO' 与 App\Foo\BarDTO::class 必须归一成同一个键，
        // 否则同一个 DTO 会注册出两个组件、撞上短类名冲突检查。
        return new self(self::KIND_LEAF, ltrim($name, '\\'), null);
    }

    private static function listOf(self $item): self
    {
        return new self(self::KIND_LIST, '', $item);
    }

    private static function mapOf(self $item): self
    {
        return new self(self::KIND_MAP, '', $item);
    }
}
