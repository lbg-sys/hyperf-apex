<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\ApiDoc\Builder\ErrorResponseCatalog;
use Hyperf\Apex\ApiDoc\Compiler\SchemaFragmentTranslator;
use Hyperf\Apex\Enum\ErrorCode;
use OpenApi\Spec as OA;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 错误响应组件的契约。
 *
 * 两个关键判据：只注册**被引用过**的状态码（否则文档里会留一堆无人引用的组件），
 * 以及同一个状态码下的所有 ErrorCode 都出现在 code 字段的 enum 里（400 既是业务失败也是参数错误）。
 */
final class ErrorResponseCatalogTest extends TestCase
{
    public function testOnlyReferencedStatusesBecomeComponents(): void
    {
        $catalog = $this->catalog();
        $catalog->reference(400);

        $components = $catalog->components();
        $this->assertCount(1, $components);
        $this->assertSame('BadRequest', $components[0]->response);
    }

    public function testRepeatedReferenceIsDeduplicatedAndSortedByStatus(): void
    {
        $catalog = $this->catalog();
        $internal = $catalog->reference(500);
        $catalog->reference(400);
        $again = $catalog->reference(500);

        $this->assertSame('#/components/responses/InternalServerError', $internal->ref);
        $this->assertSame($internal->ref, $again->ref);

        // 按状态码升序：产物要跨环境稳定（快照测试逐字节比对）。
        $this->assertSame(
            ['BadRequest', 'InternalServerError'],
            array_map(static fn (OA\Response $response): string => (string) $response->response, $catalog->components()),
        );
    }

    public function testComponentNameIsTheStatusEnumCaseName(): void
    {
        $this->assertSame('BadRequest', ErrorResponseCatalog::componentName(400));
        $this->assertSame('UnprocessableEntity', ErrorResponseCatalog::componentName(422));
        $this->assertSame('InternalServerError', ErrorResponseCatalog::componentName(500));
    }

    public function testStatusCodeOutsideTheEnumIsRejected(): void
    {
        // 状态码全部来自 ErrorCode::getHttpStatus()，绕过枚举传裸状态码是缺陷而不是需要兜底的情况。
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('999 不是 HttpStatus 枚举里的状态码');

        ErrorResponseCatalog::componentName(999);
    }

    public function testCodeEnumListsEveryErrorCodeOfThatStatus(): void
    {
        $catalog = $this->catalog();
        $catalog->reference(400);

        $code = $this->property($catalog->components()[0], 'code');
        $schema = $code->schema;
        $this->assertInstanceOf(OA\Schema::class, $schema);

        // 400 同时对应「业务处理失败」与「请求参数错误」，两个值都要能被调用方看到。
        $this->assertSame(
            [ErrorCode::BUSINESS_ERROR->value, ErrorCode::INVALID_ARGUMENT->value],
            $schema->enum,
        );
        $this->assertSame(
            [ErrorCode::BUSINESS_ERROR->getMessage(), ErrorCode::INVALID_ARGUMENT->getMessage()],
            $schema->x['enum-descriptions'] ?? null,
        );
        $this->assertSame(
            [ErrorCode::BUSINESS_ERROR->name, ErrorCode::INVALID_ARGUMENT->name],
            $schema->x['enum-varnames'] ?? null,
        );
    }

    public function testValidationStatusHasItsOwnDataShape(): void
    {
        $catalog = $this->catalog();
        $catalog->reference(422);

        $data = $this->property($catalog->components()[0], 'data')->schema;
        $this->assertInstanceOf(OA\Schema::class, $data);
        // 422 的 data 是 {errors: {字段: [消息]}}，与其它错误的 oneOf 形态完全不同。
        $this->assertSame('object', $data->type);
        $this->assertIsArray($data->properties);
        $this->assertSame('errors', $data->properties[0]->property);
    }

    public function testOtherErrorsDescribeBothDiagnosticShapes(): void
    {
        $catalog = $this->catalog();
        $catalog->reference(404);

        $data = $this->property($catalog->components()[0], 'data')->schema;
        $this->assertInstanceOf(OA\Schema::class, $data);
        // 无诊断数据时是 JSON 空数组、带诊断信息时是键值对象；只给 description 等于完全不约束。
        $this->assertIsArray($data->oneOf);
        $this->assertCount(2, $data->oneOf);
    }

    private function property(OA\Response $component, string $name): OA\Property
    {
        $content = $component->content;
        $this->assertIsArray($content);
        $schema = $content[0]->schema ?? null;
        $this->assertInstanceOf(OA\Schema::class, $schema);

        foreach ($schema->properties ?? [] as $property) {
            if ($property instanceof OA\Property && $property->property === $name) {
                return $property;
            }
        }

        $this->fail(sprintf('错误响应组件里应当有 %s 字段。', $name));
    }

    private function catalog(): ErrorResponseCatalog
    {
        return new ErrorResponseCatalog(new SchemaFragmentTranslator());
    }
}
