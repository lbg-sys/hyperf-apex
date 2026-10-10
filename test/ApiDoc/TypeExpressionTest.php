<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\DTO\Metadata\TypeExpression;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TypeExpressionTest extends TestCase
{
    public function testLeafType(): void
    {
        $expr = TypeExpression::parse('string');
        $this->assertSame(TypeExpression::KIND_LEAF, $expr->kind);
        $this->assertSame('string', $expr->name);
        $this->assertNull($expr->item);
    }

    public function testListType(): void
    {
        $expr = TypeExpression::parse('list<int>');
        $this->assertSame(TypeExpression::KIND_LIST, $expr->kind);
        $this->assertNotNull($expr->item);
        $this->assertSame(TypeExpression::KIND_LEAF, $expr->item->kind);
        $this->assertSame('int', $expr->item->name);
    }

    public function testArraySyntaxSuffix(): void
    {
        $expr = TypeExpression::parse('string[]');
        $this->assertSame(TypeExpression::KIND_LIST, $expr->kind);
        $this->assertNotNull($expr->item);
        $this->assertSame('string', $expr->item->name);
    }

    public function testMapType(): void
    {
        $expr = TypeExpression::parse('array<string, int>');
        $this->assertSame(TypeExpression::KIND_MAP, $expr->kind);
        $this->assertNotNull($expr->item);
        $this->assertSame('int', $expr->item->name);
    }

    public function testNestedList(): void
    {
        $expr = TypeExpression::parse('list<list<string>>');
        $this->assertSame(TypeExpression::KIND_LIST, $expr->kind);
        $this->assertNotNull($expr->item);
        $this->assertSame(TypeExpression::KIND_LIST, $expr->item->kind);
        $this->assertNotNull($expr->item->item);
        $this->assertSame('string', $expr->item->item->name);
    }

    public function testInvalidExpressionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TypeExpression::parse('invalid<a, b, c>');
    }
}
