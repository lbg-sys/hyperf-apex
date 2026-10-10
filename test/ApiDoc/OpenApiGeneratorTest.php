<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\ApiDoc\OpenApiGenerator;
use Hyperf\Apex\Test\ApiDoc\Support\OpenApiGeneratorHarness;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 端到端：扫描夹具目录 → 装配规格 → 编译成文档。
 *
 * 走的是生成命令同一条配线（`OpenApiGeneratorFactory`），只把扫描目录换成 fixtures，
 * 因此这里锁住的正是「`api-doc:generate` 会产出什么」：operationId 的派生方式、
 * 访问声明驱动的错误响应补全、请求 DTO 落成参数、响应信封的 data 指向响应 DTO，
 * 以及最后一条 —— 产物必须能通过生成器自己的校验（任何 error/warning 都会让命令失败）。
 */
final class OpenApiGeneratorTest extends TestCase
{
    public function testDocumentCarriesInfoServersAndOpenApiVersion(): void
    {
        $document = $this->generate();

        $this->assertSame('3.1.1', $document['openapi']);
        $this->assertSame('示例接口', $document['info']['title']);
        $this->assertSame('1.0.0', $document['info']['version']);
        $this->assertSame([['url' => 'https://api.example.com', 'description' => '生产']], $document['servers']);
    }

    public function testPublicOperationHasNoSecurityAndNoUnauthorizedResponse(): void
    {
        $operation = $this->operation('/fixture/get_list', 'get');

        // operationId 由 HTTP 方法 + URL path 派生，控制器改名或挪目录都不影响它。
        $this->assertSame('get_fixture_get_list', $operation['operationId']);
        $this->assertSame('查询条目列表', $operation['summary']);
        $this->assertSame(['示例'], $operation['tags']);
        $this->assertSame('示例/条目', $operation['x-apifox-folder']);

        // 补全判据是访问声明，不是「谁会抛这个错」：公开接口不补 401，也不挂 security。
        $this->assertArrayNotHasKey('security', $operation);
        $this->assertArrayNotHasKey('401', $operation['responses']);
        foreach (['400', '422', '500'] as $status) {
            $this->assertArrayHasKey($status, $operation['responses']);
        }
    }

    public function testAuthenticatedOperationGetsSchemeAndUnauthorized(): void
    {
        $document = $this->generate();
        $operation = $document['paths']['/fixture/create']['post'];

        $this->assertSame([['bearerAuth' => []]], $operation['security']);
        $this->assertArrayHasKey('401', $operation['responses']);
        $this->assertArrayHasKey('bearerAuth', $document['components']['securitySchemes']);
    }

    public function testQueryDtoBecomesParametersWithInferredConstraints(): void
    {
        $parameters = $this->operation('/fixture/get_list', 'get')['parameters'];
        $this->assertIsArray($parameters);

        $names = array_column($parameters, 'name');
        $this->assertSame(['page', 'keyword'], $names);

        $page = $parameters[0];
        $this->assertSame('query', $page['in']);
        // 有默认值的参数不是必填，但默认值要写出来（消费方看不到 code 缺省是 1）。
        $this->assertFalse($page['required']);
        $this->assertSame('页码', $page['schema']['description']);
        $this->assertSame(1, $page['schema']['minimum']);
        $this->assertSame(1, $page['schema']['default']);
        $this->assertSame(32, $parameters[1]['schema']['maxLength']);
    }

    public function testResponseEnvelopePointsDataAtTheResponseDto(): void
    {
        $schema = $this->successSchema('/fixture/get_list', 'get');

        $this->assertSame('#/components/schemas/FixtureItemDTO', $schema['properties']['data']['$ref']);
        $this->assertSame(['code', 'message', 'data'], $schema['required']);

        // 返回 void 时不额外定义一个空 DTO，data 直接是 type: null。
        $this->assertSame('null', $this->successSchema('/fixture/create', 'post')['properties']['data']['type']);
    }

    public function testRequestDtoBecomesRequestBody(): void
    {
        $operation = $this->operation('/fixture/create', 'post');

        $this->assertSame(
            '#/components/schemas/FixtureCreateDTO',
            $operation['requestBody']['content']['application/json']['schema']['$ref'],
        );
    }

    public function testComponentSchemasAreRegisteredWithDeclaredConstraints(): void
    {
        $schemas = $this->generate()['components']['schemas'];
        $this->assertIsArray($schemas);

        // 请求侧的 query DTO 被内联成 parameters，不额外占一个组件。
        $this->assertArrayNotHasKey('FixtureQueryDTO', $schemas);
        $this->assertArrayHasKey('FixtureCreateDTO', $schemas);

        $item = $schemas['FixtureItemDTO']['properties'];
        // #[IntAsString]：int 在文档里是带 pattern 的字符串，避免前端丢精度。
        $this->assertSame('string', $item['id']['type']);
        $this->assertSame('^-?\d+$', $item['id']['pattern']);
        $this->assertSame('string', $item['name']['type']);
    }

    public function testApiErrorDeclarationsAddOnlyTheSpecificStatuses(): void
    {
        // 状态码在文档里是数字键，转成字符串再断言，免得被 int/string 的身份骗过去。
        $statuses = array_map('strval', array_keys($this->operation('/advanced/get_detail', 'get')['responses']));

        // 通用错误之外，本接口特有的 404 / 409 由 #[ApiError] 补上。
        $this->assertContains('404', $statuses);
        $this->assertContains('409', $statuses);
        // 声明了 #[RateLimit] 就补 429 —— 不补等于文档宣称这个接口不会限流。
        $this->assertContains('429', $statuses);
        // 公开接口不补 401。
        $this->assertNotContains('401', $statuses);
    }

    public function testGenericPaginationBecomesItsOwnComponentWithItemReference(): void
    {
        $this->assertSame(
            '#/components/schemas/PaginationResultDTO_list_FixtureItemDTO',
            $this->successSchema('/advanced/get_page', 'get')['properties']['data']['$ref'],
        );

        // 每种元素类型注册成独立组件：内联展开会让每个分页接口重复一份同样的结构，
        // 客户端也就拿不到一个具名类型。
        $component = $this->generate()['components']['schemas']['PaginationResultDTO_list_FixtureItemDTO'];
        $this->assertSame('#/components/schemas/FixtureItemDTO', $component['properties']['list']['items']['$ref']);
        $this->assertSame(['list', 'total'], $component['required']);
    }

    public function testMultipartUploadMergesFormDtoAndFileField(): void
    {
        $body = $this->operation('/advanced/upload', 'post')['requestBody'];
        $form = $body['content']['multipart/form-data'];

        // 文件不经过 DTO（Service 从 file() 取），类型系统里没有它的痕迹，只能靠声明补进来。
        $this->assertSame('binary', $form['schema']['properties']['file']['format']);
        $this->assertSame('image/*', $form['schema']['properties']['file']['contentMediaType']);
        $this->assertSame(['file'], $form['schema']['required']);
        // 表单 DTO 的字段与文件字段合并成同一个 form。
        $this->assertArrayHasKey('remark', $form['schema']['properties']);
        $this->assertSame('image/*', $form['encoding']['file']['contentType']);
    }

    public function testGeneratedDocumentPassesTheGeneratorsOwnValidation(): void
    {
        $generator = $this->generator();

        // 这是生成命令的成败判据：任何 error 或 warning 都会让它退出非零。
        $this->assertSame([], $generator->validate($generator->specification($this->endpointPath())));
    }

    public function testExcludedPatternKeepsControllerOutOfTheDocument(): void
    {
        $document = $this->generator(['excluded' => ['*FixtureController']])->generate($this->endpointPath());

        $this->assertArrayNotHasKey('/fixture/get_list', $document['paths'] ?? []);
        $this->assertArrayNotHasKey('/fixture/create', $document['paths'] ?? []);
    }

    public function testMissingInfoTitleIsRejectedBeforeScanning(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('api_doc.info.title 必须配置。');

        $this->generator(['info' => ['version' => '1.0.0']])->specification($this->endpointPath());
    }

    public function testServerWithoutUrlIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('api_doc.servers[0] 缺少 url。');

        $this->generator(['servers' => [['description' => '生产']]])->specification($this->endpointPath());
    }

    public function testThreeZeroTargetRejectsThreeOneOnlyDeclarations(): void
    {
        // 目标 3.0.x 时声明 3.1 专有词汇必须失败，而不是产出一份少了一条约束的文档
        // （编译层对这些关键字没有任何报错，只是不输出）。
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('patternProperties');

        $this->generator(['version' => '3.0.3'])->generate(__DIR__ . '/EndpointVersion30');
    }

    public function testThreeZeroTargetStillGeneratesForCompatibleDeclarations(): void
    {
        // 收口不能过严：只用 3.0 能表达的声明时，3.0 目标照常产出且能通过校验。
        // （主夹具里用了 #[FileField(mediaType:)]，那会产出 contentMediaType —— 3.1 专有，
        // 所以这里用一份刻意只含 3.0 词汇的夹具。）
        $generator = $this->generator(['version' => '3.0.3']);
        $document = $generator->generate(__DIR__ . '/EndpointVersion30Compatible');

        $this->assertSame('3.0.3', $document['openapi']);
        $this->assertSame('string', $document['components']['schemas']['SafeItemDTO']['properties']['name']['type']);
        $this->assertSame([], $generator->validate($generator->specification(__DIR__ . '/EndpointVersion30Compatible')));
    }

    public function testTwoUrlsNormalizingToTheSameOperationIdAreRejected(): void
    {
        // 编译器自带的 validate() 发现不了这种冲突，靠生成器自己兜底。
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operationId get_fixture_get_list 重复');

        $this->generator()->generate(__DIR__ . '/EndpointCollision');
    }

    /** @return array<string, mixed> */
    private function generate(): array
    {
        return $this->generator()->generate($this->endpointPath());
    }

    /**
     * @param array<string, mixed> $apiDoc
     */
    private function generator(array $apiDoc = []): OpenApiGenerator
    {
        return OpenApiGeneratorHarness::generator($apiDoc);
    }

    private function endpointPath(): string
    {
        return __DIR__ . '/Endpoint';
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(string $path, string $method): array
    {
        $operation = $this->generate()['paths'][$path][$method] ?? null;
        $this->assertIsArray($operation, sprintf('文档里应当有 %s %s。', $method, $path));

        return $operation;
    }

    /**
     * @return array<string, mixed>
     */
    private function successSchema(string $path, string $method): array
    {
        $schema = $this->operation($path, $method)['responses']['200']['content']['application/json']['schema'] ?? null;
        $this->assertIsArray($schema, '成功响应应当有 JSON 结构。');

        return $schema;
    }
}
