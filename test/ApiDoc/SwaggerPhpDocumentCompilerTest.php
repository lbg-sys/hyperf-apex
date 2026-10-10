<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\ApiDoc\Compiler\SwaggerPhpDocumentCompiler;
use OpenApi\Spec as OA;
use OpenApi\Specification;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 编译层的契约：按目标版本选编译器、把版本差异交给它、校验结果按 error/warning 分级返回。
 *
 * 断言里的期望值来自实测（不是猜的）：3.0 把类型数组降级成 `type + nullable`、把 `const` 落成
 * 单值 `enum`，而 `prefixItems`、`if/then/else` 在 3.0 里没有对应表达会被**直接丢弃** ——
 * 最后这条用「已知行为」的断言记录下来，见用例注释。
 */
final class SwaggerPhpDocumentCompilerTest extends TestCase
{
    public function testVersionIsReportedAsConfigured(): void
    {
        $this->assertSame('3.1.1', (new SwaggerPhpDocumentCompiler('3.1.1'))->version());
        $this->assertSame('3.0.3', (new SwaggerPhpDocumentCompiler('3.0.3'))->version());
    }

    public function testUnsupportedVersionIsRejectedAtConstruction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('不支持的 OpenAPI 版本：4.0.0');

        new SwaggerPhpDocumentCompiler('4.0.0');
    }

    public function testCompileKeepsThreeOneOnlyKeywords(): void
    {
        $document = (new SwaggerPhpDocumentCompiler('3.1.1'))->compile($this->specification());

        $this->assertSame('3.1.1', $document['openapi']);

        $sample = $this->sample($document);
        $this->assertSame(['string', 'null'], $sample['type']);
        $this->assertSame([['type' => 'string']], $sample['prefixItems']);
        $this->assertSame('fixed', $sample['const']);

        // 显式 null 是一个值（不是「没写」），编译后必须还在。
        $this->assertArrayHasKey('example', $sample);
        $this->assertNull($sample['example']);
    }

    public function testCompileDowngradesForThreeZero(): void
    {
        $document = (new SwaggerPhpDocumentCompiler('3.0.3'))->compile($this->specification());

        $this->assertSame('3.0.3', $document['openapi']);

        $sample = $this->sample($document);
        // 3.0 用 type + nullable 表达可空，const 落成单值 enum。
        $this->assertSame('string', $sample['type']);
        $this->assertTrue($sample['nullable']);
        $this->assertSame(['fixed'], $sample['enum']);
        $this->assertArrayNotHasKey('const', $sample);

        /*
         * 已知行为（不是期望行为）：prefixItems 与 if/then/else 在 3.0 里没有对应表达，
         * 编译层直接丢弃。也就是说目标版本选 3.0.x 时，DTO 上声明的这两类约束会从文档里
         * 静默消失 —— 与项目「要么生成正确文档、要么报错」的原则不一致，这里把它钉成断言，
         * 是为了将来真要收口时能看见行为变化。
         */
        $this->assertArrayNotHasKey('prefixItems', $sample);
    }

    public function testValidateReportsErrorsAndWarningsWithLevels(): void
    {
        $entries = (new SwaggerPhpDocumentCompiler('3.1.1'))->validate(new Specification());

        $byLevel = [];
        foreach ($entries as $entry) {
            $byLevel[$entry['level']][] = $entry['message'];
        }

        $this->assertContains('info is required', $byLevel['error'] ?? []);
        $this->assertContains(
            'At least one of paths, webhooks, or components is required',
            $byLevel['warning'] ?? [],
        );
    }

    public function testValidateFlagsArrayWithoutItems(): void
    {
        // 这是项目最在意的一类问题：type: array 却没有 items，客户端无从知道元素类型。
        $entries = (new SwaggerPhpDocumentCompiler('3.1.1'))->validate($this->specification());

        $this->assertSame(['warning'], array_column($entries, 'level'));
        $this->assertStringContainsString(
            'has type "array" but no items',
            implode("\n", array_column($entries, 'message')),
        );
    }

    public function testValidateReturnsNoEntriesForWellFormedSpecification(): void
    {
        $specification = new Specification();
        $specification->add(new OA\Info(version: '1.0.0', title: '示例'));
        $specification->add(new OA\Schema(component: 'Clean', type: 'string'));

        $this->assertSame([], (new SwaggerPhpDocumentCompiler('3.1.1'))->validate($specification));
    }

    /**
     * 一份同时含 3.1 专有词汇与「array 没有 items」的规格。
     */
    private function specification(): Specification
    {
        $specification = new Specification();
        $specification->add(new OA\Info(version: '1.0.0', title: '示例'));
        $specification->add(new OA\Schema(
            component: 'Sample',
            type: ['string', 'null'],
            const: 'fixed',
            prefixItems: [new OA\Schema(type: 'string')],
            example: null,
        ));
        $specification->add(new OA\Schema(component: 'WithoutItems', type: 'array'));

        return $specification;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function sample(array $document): array
    {
        $sample = $document['components']['schemas']['Sample'] ?? null;
        $this->assertIsArray($sample, '组件 Sample 应当出现在文档里。');

        return $sample;
    }
}
