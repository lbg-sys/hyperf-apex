<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Contract;

/**
 * 对可标注的 PHP 属性类型有要求的 Schema Attribute。
 *
 * 存在的理由：有些 Attribute 只在特定源类型上成立，换个源类型就会产出「语法合法但语义骗人」
 * 的文档与数据。典型例子是「整数出站成字符串」—— 同一个目标类型 string，int 过来是无损可逆的，
 * float 过来却受 precision ini 影响会写出 `(string) (0.1 + 0.2) === "0.3"`，bool 过来会产出空串。
 * 也就是说判据必须是「源类型 => 目标类型」这一对，而不是目标类型本身。
 *
 * 这种约束不能写在 DTOSchemaFactory 里 —— 那里禁止出现具体 Attribute 类名，否则每加一个
 * 受限属性就要改工厂。由 Attribute 自己声明允许的源类型，工厂只做统一的接口判断。
 */
interface TypedSchemaAttribute extends SchemaAttribute
{
    /**
     * 允许标注的 PHP 属性类型名，取值与 ReflectionNamedType::getName() 一致。
     *
     * 未声明类型（无类型属性）也会被拒绝：没有确定的源类型就无从判断这种转换是否成立。
     *
     * @return list<string>
     */
    public function propertyTypes(): array;
}
