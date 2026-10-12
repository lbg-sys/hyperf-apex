<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\DocumentationOnlyAttribute;
use Hyperf\Apex\DTO\Contract\TypedSchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;

/**
 * 整数的字符串表示：PHP 侧保持 int，出站到 JSON 时序列化成十进制字符串。
 *
 * 为什么需要它：超过 JS `Number.MAX_SAFE_INTEGER`（2^53-1）的整数在浏览器端会静默丢精度，
 * 雪花 ID 这类 64 位标识第一个就超。声明成字符串后，前端拿到的是精确值而不是被取整的数字。
 *
 * 边界，必须守住，否则这个注解会从「精度修复」变成「精度漏洞」：
 * - 只适用于 `int => string` 这一对类型。`float => string` 受 precision ini 影响会写出
 *   `(string) (0.1 + 0.2) === "0.3"`，`bool => string` 会产出空串 —— 它们都需要专门的
 *   类型或序列化器，不是 cast。类型对由 TypedSchemaAttribute 声明，DTOSchemaFactory 在
 *   构建期强制，标错地方会让 api-doc:generate 直接失败。
 * - 只声明 cast，不声明 transform。金额（分转元）、时间格式化、编码解码都要计算，
 *   放在 `fromModel()` 或专门的类型里，不要往这个注解上堆。
 * - 与 #[Pattern] 互斥：两者都写 pattern 关键字，DTOSchemaFactory::rejectConflicts()
 *   会因「产出取决于声明顺序」直接报错。同理不要在同一字段上用 #[Length]，那是数值语义
 *   的 min/max，而这里已经换成字符串语义。
 * - 面向响应 DTO。用在请求 DTO 上时校验规则仍按 PHP 类型生成（integer），数字字符串能
 *   通过，所以不会与服务端行为冲突，只是文档看起来比服务端更严。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IntAsString implements TypedSchemaAttribute, DocumentationOnlyAttribute
{
    /**
     * 十进制整数串。
     *
     * 允许前导负号：PHP 的 int 本身有负数，写成 `^\d+$` 会让文档对负值说谎。pattern 会被
     * 客户端当校验规则用（Laravel/JSON Schema 生态都这么做），宁可放宽也不能写出会误拒
     * 合法值的约束 —— ID 场景下负号不会出现，代价只是文档多一个可选分支。
     */
    private const PATTERN = '^-?\d+$';

    public function propertyTypes(): array
    {
        return ['int'];
    }

    public function fragment(): SchemaFragment
    {
        return SchemaFragment::of([
            'type' => 'string',
            'pattern' => self::PATTERN,
        ]);
    }
}
