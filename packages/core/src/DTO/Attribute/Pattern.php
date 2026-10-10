<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Contract\ValidationRuleAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

/**
 * 正则约束。$value 写 PCRE 形式（带分隔符与可选修饰符），运行时原样交给 regex 规则，
 * 文档侧剥掉分隔符与修饰符 —— JSON Schema 的 pattern 是裸正则。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Pattern implements ValidationRuleAttribute, SchemaAttribute
{
    /** PCRE 允许的成对分隔符：开字符 => 闭字符。 */
    private const PAIRED_DELIMITERS = ['(' => ')', '{' => '}', '[' => ']', '<' => '>'];

    public function __construct(public readonly string $value)
    {
    }

    public function validationRules(): array
    {
        return ['regex:' . $this->value];
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of(['pattern' => self::bareRegex($this->value)]);
    }

    /**
     * 剥掉 PCRE 分隔符。
     *
     * 用 strrpos 找「与开字符相同的字符」对 (...)、{...}、[...]、<...> 这类
     * 开闭不同的分隔符是失效的 —— 那会原样输出，把分隔符留在文档里，
     * 而 (^\d+$) 进了 pattern 之后整个模式会变成一个捕获组，语义被改。
     */
    private static function bareRegex(string $value): string
    {
        $open = $value[0] ?? '';
        if ($open === '' || ctype_alnum($open) || $open === '\\') {
            return $value;
        }

        $close = self::PAIRED_DELIMITERS[$open] ?? $open;
        $end = strrpos($value, $close);

        return $end === false || $end === 0 ? $value : substr($value, 1, $end - 1);
    }
}
