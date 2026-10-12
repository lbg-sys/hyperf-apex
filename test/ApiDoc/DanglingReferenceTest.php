<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\Test\ApiDoc\Support\OpenApiGeneratorHarness;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 组件引用的构建期一致性。
 *
 * 悬空 `$ref` 正是编译器自带的 validate() 查不出来的那一类问题：语法合法、语义骗人 ——
 * 要等下游导入文档时才报「找不到组件」。这里在生成期拦住它。
 */
final class DanglingReferenceTest extends TestCase
{
    public function testDanglingComponentReferenceIsRejected(): void
    {
        // #[Discriminator] 的 mapping 按短类名直接拼引用、不注册组件：mapping 里的类必须同时
        // 出现在 #[OneOf] 里。漏写时产物留下悬空引用，必须在生成期报错。
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('引用了未注册的组件');

        OpenApiGeneratorHarness::generator()->specification(__DIR__ . '/EndpointDangling');
    }

    public function testWellFormedEndpointsHaveNoDanglingReferences(): void
    {
        // 既有样例目录必须干净通过：断言不能把正常文档也拦下。
        OpenApiGeneratorHarness::generator()->specification(__DIR__ . '/Endpoint');

        $this->addToAssertionCount(1);
    }
}
