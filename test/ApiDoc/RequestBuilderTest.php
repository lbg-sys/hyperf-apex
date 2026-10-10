<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\ApiDoc\Attribute\ApiHeader;
use Hyperf\Apex\ApiDoc\Attribute\FileField;
use Hyperf\Apex\ApiDoc\Builder\RequestBuilder;
use Hyperf\Apex\ApiDoc\Compiler\SchemaFragmentTranslator;
use Hyperf\Apex\ApiDoc\DTOSchemaFactory;
use Hyperf\Apex\DTO\Attribute\FieldDescription;
use Hyperf\Apex\DTO\Attribute\Minimum;
use Hyperf\Apex\Http\ArgumentResolver\Attribute\FromBody;
use Hyperf\Apex\Http\ArgumentResolver\Attribute\FromMultipart;
use Hyperf\Apex\Http\ArgumentResolver\Attribute\FromQuery;
use Hyperf\Apex\Http\Middleware\IdempotencyMiddleware;
use Hyperf\Apex\Idempotency\Attribute\Idempotent;
use OpenApi\Spec as OA;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * 请求描述的推导契约。
 *
 * 三条主线：
 * - 头部参数：类级 `#[ApiHeader]` 与方法级合并，方法级同名覆盖；`#[Idempotent]` 的请求 ID 若不由
 *   请求体承载，就必须补出 `Idempotency-Key` 头，否则调用方不知道要传它；
 * - 查询参数：DTO 拍平成逐个参数（不注册组件，避免孤儿），标量参数按「有默认值/可空」判必填，
 *   数组参数要给出真实的方括号语法（OpenAPI 的 style 表达不了它）；
 * - 请求体：DTO 出 `$ref`、散装标量合成对象、multipart 与两者合并或互斥。
 *
 * 表达不出来的组合一律在构建期报错：重复的查询参数名、两个 body DTO、DTO 与散装标量混用、
 * multipart 与 JSON 请求体共存、联合类型的散装参数。
 */
final class RequestBuilderTest extends TestCase
{
    public function testHeaderParametersMergeWithMethodWinning(): void
    {
        $headers = $this->headers('withHeaders');

        $this->assertSame(['X-Tenant-Id', 'X-Trace-Id'], array_map(static fn (OA\Parameter $p): string => (string) $p->name, $headers));
        $this->assertSame('header', $headers[0]->in);
        $this->assertTrue($headers[0]->required);
        // 方法级同名声明覆盖类级：说明与必填都按方法级算。
        $this->assertSame('方法级覆盖', $headers[1]->description);
        $this->assertTrue($headers[1]->required);
    }

    public function testIdempotentAddsTheKeyHeaderWhenIdIsNotInTheBody(): void
    {
        // 类级 #[ApiHeader] 对每个方法都生效，所以这里只挑幂等头出来断言。
        $header = $this->header(IdempotencyMiddleware::HEADER, 'withIdempotent');

        $this->assertTrue($header->required);
        $this->assertSame(8, $header->schema->minLength);
        $this->assertSame(128, $header->schema->maxLength);
    }

    public function testIdempotentWithRequestBodyFieldDoesNotAddTheHeader(): void
    {
        // 请求 ID 由请求体字段承载时不该再要求一个头，否则同一件事有两个入口。
        $this->assertNotContains(
            IdempotencyMiddleware::HEADER,
            array_map(static fn (OA\Parameter $p): string => (string) $p->name, $this->headers('withIdempotentFromBody')),
        );
    }

    public function testQueryDtoIsFlattenedWithoutRegisteringAComponent(): void
    {
        $schemas = new DTOSchemaFactory();
        $parameters = $this->build('queryDto', $schemas)['parameters'];

        $this->assertSame(['keyword', 'page'], array_map(static fn (OA\Parameter $p): string => (string) $p->name, $parameters));
        // 必填来自 DTO 自身的 required 推断：无默认值 → 必填。
        $this->assertTrue($parameters[0]->required);
        $this->assertFalse($parameters[1]->required);
        $this->assertSame(1, $parameters[1]->schema->minimum);

        // 字段已经全部铺进 query 参数，不该再留下无人引用的孤儿组件。
        $this->assertSame([], $schemas->componentFragments());
    }

    public function testScalarQueryParameterInferRequiredAndNameOverride(): void
    {
        $parameters = $this->build('scalarQuery')['parameters'];

        $this->assertSame(['page', 'kw'], array_map(static fn (OA\Parameter $p): string => (string) $p->name, $parameters));
        // 有默认值的参数不必填，且默认值要写出来（消费方看不到缺省是 1）。
        $this->assertFalse($parameters[0]->required);
        $this->assertSame(1, $parameters[0]->schema->default);
        // 可空参数即使没有默认值也不是必填。
        $this->assertFalse($parameters[1]->required);
        $this->assertTrue($parameters[1]->schema->nullable);
    }

    public function testArrayQueryParameterGetsBracketSyntaxHint(): void
    {
        $parameters = $this->build('arrayQuery')['parameters'];

        $this->assertSame('array', $parameters[0]->schema->type);
        // OpenAPI 的 style/explode 表达不了 PHP 的方括号数组语法，所以把真实格式写进说明。
        $this->assertSame(
            '数组参数使用方括号语法重复传递，例如 ?codes[]=a&codes[]=b',
            $parameters[0]->schema->description,
        );
    }

    public function testBodyDtoBecomesJsonReferenceBody(): void
    {
        $result = $this->build('bodyDto');

        $this->assertSame([], $result['parameters']);
        $body = $result['requestBody'];
        $this->assertInstanceOf(OA\RequestBody::class, $body);
        $this->assertTrue($body->required);
        $content = $body->content;
        $this->assertIsArray($content);
        $this->assertSame('application/json', $content[0]->mediaType);
        $this->assertSame('#/components/schemas/BodyFixtureDTO', $content[0]->schema->ref);
    }

    public function testScalarBodyParametersBecomeOneJsonObject(): void
    {
        $body = $this->build('bodyScalars')['requestBody'];

        $this->assertInstanceOf(OA\RequestBody::class, $body);
        $content = $body->content;
        $this->assertIsArray($content);
        $schema = $content[0]->schema;
        $this->assertInstanceOf(OA\Schema::class, $schema);

        $this->assertSame(['name', 'amount'], array_map(static fn (OA\Property $p): string => (string) $p->property, $schema->properties));
        $this->assertSame(['name', 'amount'], $schema->required);
        $this->assertSame('integer', $schema->properties[1]->schema->type);
    }

    public function testParametersWithoutAnnotationAreIgnored(): void
    {
        // 没标注的参数不进文档：它不来自请求（例如容器注入的服务）。
        $result = $this->build('noAnnotations');

        $this->assertSame([], $result['parameters']);
        $this->assertNull($result['requestBody']);
    }

    public function testTwoBodyDtosAreRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('有多个 #[FromBody] DTO 参数');

        $this->build('twoBodyDtos');
    }

    public function testBodyDtoMixedWithScalarBodyParametersIsRejected(): void
    {
        // 标量部分不会进文档，留着就是「接口实际收的东西比文档多」。
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('同时用 #[FromBody] 声明了 DTO 和散装标量参数');

        $this->build('dtoAndScalars');
    }

    public function testMultipartMergesFormDtoAndFileFields(): void
    {
        $body = $this->build('multipart')['requestBody'];

        $this->assertInstanceOf(OA\RequestBody::class, $body);
        $content = $body->content;
        $this->assertIsArray($content);
        $this->assertSame('multipart/form-data', $content[0]->mediaType);

        $schema = $content[0]->schema;
        $this->assertInstanceOf(OA\Schema::class, $schema);
        $properties = [];
        foreach ($schema->properties ?? [] as $property) {
            $properties[$property->property] = $property->schema;
        }

        $this->assertSame('binary', $properties['file']->format);
        $this->assertSame('image/*', $properties['file']->contentMediaType);
        $this->assertSame('string', $properties['remark']->type);
        $this->assertSame(['file'], $schema->required);

        // 限定了内容类型才产出 encoding：不限时编码没有可写的内容。
        $this->assertIsArray($content[0]->encoding);
        $this->assertSame('file', $content[0]->encoding[0]->encoding);
        $this->assertSame('image/*', $content[0]->encoding[0]->contentType);
    }

    public function testMultipleFileFieldBecomesAnArrayWithDefaultContentType(): void
    {
        $content = $this->build('multipartMultiple')['requestBody']->content;
        $this->assertIsArray($content);
        $schema = $content[0]->schema;
        $this->assertInstanceOf(OA\Schema::class, $schema);

        $files = null;
        foreach ($schema->properties ?? [] as $property) {
            if ($property->property === 'files') {
                $files = $property->schema;
            }
        }

        $this->assertInstanceOf(OA\Schema::class, $files);
        $this->assertSame('array', $files->type);
        $this->assertSame('binary', $files->items->format);
        // 没限定内容类型时兜底成通用二进制，而不是留空。
        $this->assertSame('application/octet-stream', $files->items->contentMediaType);
        // 没限定类型就没有 encoding。
        $this->assertNull($content[0]->encoding);
    }

    public function testMultipartTogetherWithJsonBodyIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('同时声明了 multipart 表单与 JSON 请求体');

        $this->build('multipartWithJsonBody');
    }

    public function testDuplicateQueryParameterNamesAreRejected(): void
    {
        // 两个 #[FromQuery] DTO 有同名属性时会产出两条同名参数，而 name + in 必须唯一。
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('有重复的查询参数 keyword');

        $this->build('duplicateQuery');
    }

    public function testUnionTypedScalarParameterIsRejected(): void
    {
        // 联合类型没有单一的标量表达，落成 type: string 就是撒谎。
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('散装请求参数必须是单一的标量类型');

        $this->build('unionScalar');
    }

    private function build(string $method, ?DTOSchemaFactory $schemas = null): array
    {
        $builder = new RequestBuilder($schemas ?? new DTOSchemaFactory(), new SchemaFragmentTranslator());

        return $builder->build($this->method($method));
    }

    /**
     * @return list<OA\Parameter>
     */
    private function headers(string $method): array
    {
        $builder = new RequestBuilder(new DTOSchemaFactory(), new SchemaFragmentTranslator());

        return $builder->headerParameters(new ReflectionClass(RequestBuilderFixture::class), $this->method($method));
    }

    private function header(string $name, string $method): OA\Parameter
    {
        foreach ($this->headers($method) as $header) {
            if ($header->name === $name) {
                return $header;
            }
        }

        $this->fail(sprintf('%s 应当声明 %s 头。', $method, $name));
    }

    private function method(string $name): ReflectionMethod
    {
        return new ReflectionMethod(RequestBuilderFixture::class, $name);
    }
}

/**
 * 各类参数组合的载体。方法体不会被调用 —— 本组用例只反射签名。
 */
#[ApiHeader(name: 'X-Tenant-Id', description: '租户标识', required: true)]
#[ApiHeader(name: 'X-Trace-Id', description: '类级追踪', required: false)]
final class RequestBuilderFixture
{
    #[ApiHeader(name: 'X-Trace-Id', description: '方法级覆盖', required: true)]
    public function withHeaders(#[FromQuery] QueryFixtureDTO $query): void
    {
    }

    #[Idempotent]
    public function withIdempotent(#[FromBody] BodyFixtureDTO $body): void
    {
    }

    #[Idempotent(requestIdField: 'requestId')]
    public function withIdempotentFromBody(#[FromBody] BodyFixtureDTO $body): void
    {
    }

    public function queryDto(#[FromQuery] QueryFixtureDTO $query): void
    {
    }

    public function scalarQuery(#[FromQuery] int $page = 1, #[FromQuery(name: 'kw')] ?string $keyword = null): void
    {
    }

    /** @param list<string> $codes */
    public function arrayQuery(#[FromQuery] array $codes): void
    {
    }

    public function bodyDto(#[FromBody] BodyFixtureDTO $body): void
    {
    }

    public function bodyScalars(#[FromBody] string $name, #[FromBody] int $amount): void
    {
    }

    public function noAnnotations(string $ignored): void
    {
    }

    public function twoBodyDtos(#[FromBody] BodyFixtureDTO $first, #[FromBody] BodyFixtureDTO $second): void
    {
    }

    public function dtoAndScalars(#[FromBody] BodyFixtureDTO $body, #[FromBody] string $extra): void
    {
    }

    #[FileField('file', '待上传的文件', mediaType: 'image/*')]
    public function multipart(#[FromMultipart] MultipartFixtureDTO $form): void
    {
    }

    #[FileField('files', '多文件上传', multiple: true)]
    public function multipartMultiple(#[FromMultipart] MultipartFixtureDTO $form): void
    {
    }

    #[FileField('file', '待上传的文件')]
    public function multipartWithJsonBody(#[FromMultipart] MultipartFixtureDTO $form, #[FromBody] BodyFixtureDTO $body): void
    {
    }

    public function duplicateQuery(#[FromQuery] QueryFixtureDTO $first, #[FromQuery] OtherQueryFixtureDTO $second): void
    {
    }

    public function unionScalar(#[FromQuery] int|string $value): void
    {
    }
}

final readonly class QueryFixtureDTO
{
    public function __construct(
        #[FieldDescription('关键词')]
        public string $keyword,
        #[FieldDescription('页码')]
        #[Minimum(1)]
        public int $page = 1,
    ) {
    }
}

/** 与 QueryFixtureDTO 有同名属性：用于验证重复查询参数名会被拦下。 */
final readonly class OtherQueryFixtureDTO
{
    public function __construct(
        #[FieldDescription('关键词')]
        public string $keyword = '',
    ) {
    }
}

final readonly class BodyFixtureDTO
{
    public function __construct(
        #[FieldDescription('名称')]
        public string $name = '',
    ) {
    }
}

final readonly class MultipartFixtureDTO
{
    public function __construct(
        #[FieldDescription('备注')]
        public string $remark = '',
    ) {
    }
}
