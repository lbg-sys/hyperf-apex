<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Contract;

/**
 * 标记「不参与请求校验」的 Schema Attribute。
 *
 * DTO 上的声明式约束有两条落地路径：文档（OpenAPI schema 片段）与请求校验（hyperf/validation 规则）。
 * 多数 Attribute 两条都落地（`#[Length]`、`#[Minimum]`、`#[InValues]`…），但有一类只能落在文档一侧：
 *
 * - JSON Schema 的结构关键字在 Hyperf 的校验体系里没有对应规则（`patternProperties`、
 *   `dependentRequired`、`propertyNames`、`unevaluated*`）；
 * - 少数边界关键字需要区分「数值比较」与「长度比较」，直接映射会给出错误结论
 *   （`exclusiveMinimum`、`uniqueItems`、`contains`）；
 * - 纯注解类关键字（`format`、`const`、`default`、`title`、`example`…）本就不改变服务端行为；
 * - `#[IntAsString]` 影响的是**出站序列化**（int → 十进制字符串），请求侧不参与校验。
 *
 * 实现本接口就是明确表态：**使用方不要指望服务端会拒绝不合规的值**，这条约束只描述契约。
 * 没实现本接口、又只实现 {@see SchemaAttribute} 的 Attribute，等于「看起来会校验、实际不校验」——
 * 那种状态既骗客户端也骗维护者。所以新增 Attribute 时必须在「参与校验」与「不参与校验」之间
 * 选一个：前者实现 {@see ValidationRuleAttribute} / {@see ItemRuleAttribute}，后者实现本接口。
 * `test/DTO/SchemaAttributeContractTest.php` 会逐个检查这件事。
 *
 * 反过来也成立：标了本接口的约束不能当安全边界用（例如 `patternProperties` 不能替代白名单校验），
 * 需要在服务端拒绝的规则要另挂校验 Attribute，或在 Service 里判断。
 */
interface DocumentationOnlyAttribute
{
}
