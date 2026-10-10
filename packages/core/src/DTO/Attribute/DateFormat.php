<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Contract\ValidationRuleAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use InvalidArgumentException;

/**
 * 日期时间字段。$validationFormat 是 PHP 的日期格式串，同时决定运行时校验与文档约束。
 *
 * 文档侧只在格式真正等价于 JSON Schema 的 format 时才用 format，其余情况翻译成等价的
 * pattern —— OpenAPI 的 date-time 是 RFC 3339，而 'Y-m-d H:i:s'（空格分隔、无时区）
 * 并不满足，声明成 date-time 会让调用方按 2026-08-27T10:30:00Z 发请求然后收到 422。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class DateFormat implements ValidationRuleAttribute, SchemaAttribute
{
    /** PHP 格式字符 => 对应的正则片段。只收录能精确表达的那些。 */
    private const TOKENS = [
        'Y' => '\d{4}',
        'y' => '\d{2}',
        'm' => '(?:0[1-9]|1[0-2])',
        'n' => '(?:[1-9]|1[0-2])',
        'd' => '(?:0[1-9]|[12]\d|3[01])',
        'j' => '(?:[1-9]|[12]\d|3[01])',
        'H' => '(?:[01]\d|2[0-3])',
        'G' => '(?:\d|1\d|2[0-3])',
        'i' => '[0-5]\d',
        's' => '[0-5]\d',
    ];

    /** 可以原样进正则的分隔符。刻意不含 T —— PHP 里未转义的 T 是时区缩写。 */
    private const SEPARATORS = ['-', ':', '/', ' '];

    /** 等价于 RFC 3339 date-time 的写法。 */
    private const RFC3339 = ['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:sO', 'c'];

    public function __construct(
        public readonly ?string $validationFormat = null,
        public readonly ?string $openApiFormat = null,
    ) {
    }

    public function validationRules(): array
    {
        if ($this->validationFormat === null) {
            return ['date'];
        }

        // date_format 的参数由 ValidationRuleParser 按 CSV 切分，含逗号的格式会被截断。
        if (str_contains($this->validationFormat, ',')) {
            throw new InvalidArgumentException('date_format 的格式串不能含逗号，Hyperf 会按逗号切分规则参数。');
        }

        return ['date_format:' . $this->validationFormat];
    }

    public function fragment(): SchemaFragment
    {
        if ($this->openApiFormat !== null) {
            return SchemaFragment::of(['format' => $this->openApiFormat]);
        }

        // 不给 validationFormat 时运行时走 'date' 规则，即 strtotime 能解析的任何写法。
        // 那个集合没有对应的 JSON Schema 表达，给 format: date 反而比实际更严。
        if ($this->validationFormat === null) {
            return SchemaFragment::empty();
        }

        if ($this->validationFormat === 'Y-m-d') {
            return SchemaFragment::of(['format' => 'date']);
        }

        if (in_array($this->validationFormat, self::RFC3339, true)) {
            return SchemaFragment::of(['format' => 'date-time']);
        }

        $pattern = self::pattern($this->validationFormat);

        return $pattern === null ? SchemaFragment::empty() : SchemaFragment::of(['pattern' => $pattern]);
    }

    /**
     * 把 PHP 格式串翻译成 ECMA-262 正则。出现无法精确表达的格式字符时返回 null——
     * 宁可不给约束，也不给一个错的约束。
     */
    private static function pattern(string $format): ?string
    {
        $regex = '';
        for ($index = 0, $length = strlen($format); $index < $length; ++$index) {
            $character = $format[$index];

            if ($character === '\\') {
                $literal = $format[++$index] ?? '';

                if ($literal === '' || ! ctype_alnum($literal)) {
                    return null;
                }

                $regex .= $literal;
                continue;
            }

            if (isset(self::TOKENS[$character])) {
                $regex .= self::TOKENS[$character];
                continue;
            }

            if (! in_array($character, self::SEPARATORS, true)) {
                return null;
            }

            $regex .= $character;
        }

        return $regex === '' ? null : '^' . $regex . '$';
    }
}
