<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\ApiDoc\Attribute\ApiFolder;
use Hyperf\Apex\ApiDoc\Attribute\ApiHeader;
use Hyperf\Apex\ApiDoc\Attribute\ApiOperation;
use Hyperf\Apex\ApiDoc\Builder\ControllerScanner;
use Hyperf\Apex\ApiDoc\Builder\ErrorResponseCatalog;
use Hyperf\Apex\ApiDoc\Builder\OperationBuilder;
use Hyperf\Apex\ApiDoc\Builder\RequestBuilder;
use Hyperf\Apex\ApiDoc\Builder\ResponseBuilder;
use Hyperf\Apex\ApiDoc\Builder\ReturnTypeResolver;
use Hyperf\Apex\ApiDoc\Builder\SecurityResolver;
use Hyperf\Apex\ApiDoc\Compiler\SchemaFragmentTranslator;
use Hyperf\Apex\ApiDoc\DTOSchemaFactory;
use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\DTO\Attribute\FieldDescription;
use Hyperf\Apex\Http\ArgumentResolver\Attribute\FromQuery;
use Hyperf\Apex\Http\Response\ApiResponseDTO;
use Hyperf\Apex\Http\Response\ApiResponseFactory;
use Hyperf\Apex\Http\Response\ResponseEncoderResolver;
use Hyperf\Apex\RateLimit\Attribute\RateLimit;
use Hyperf\Apex\RateLimit\RateLimitRuleReader;
use Hyperf\Apex\Security\AccessDeclarationReader;
use Hyperf\Apex\Security\Attribute\PublicAccess;
use Hyperf\Apex\Security\Attribute\RequirePermission;
use Hyperf\Apex\Test\ApiDoc\Support\JsonEncoderContainer;
use Hyperf\Config\Config;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\RequestMapping;
use OpenApi\Spec as OA;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * 单个控制器方法 → Operation 的装配契约。
 *
 * 三条判据：
 * - URL 拼装只认「显式 prefix + 相对 path」：Hyperf 会给空 path 补方法名、对绝对路径忽略 prefix，
 *   本生成器两种都不复刻 —— 于是这两种写法必须在构建期报错，而不是产出一个与真实路由不一致的 URL；
 * - 错误响应按「真实可达」推导：400/500 恒有，422 只在这个接口真有请求输入时才有，
 *   401/403/429 分别由认证、权限码与限流声明推导；
 * - 扩展字段：`x-apifox-name` 恒有、`x-apifox-folder` 来自 `#[ApiFolder]`、
 *   `x-required-permissions` 只在真有权限码时出现。
 */
final class OperationBuilderTest extends TestCase
{
    public function testControllerPrefixMustBeExplicit(): void
    {
        // Hyperf 会按类名推导前缀，文档不复刻这条规则 —— 不写就报错，别产出错 URL。
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('必须显式写出 prefix');

        $this->build(EmptyPrefixFixture::class);
    }

    public function testMappingPathMustBeExplicit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('必须显式写出动作路径');

        $this->build(EmptyMappingPathFixture::class);
    }

    public function testAbsoluteMappingPathIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('是绝对路径');

        $this->build(AbsoluteMappingPathFixture::class);
    }

    public function testTwoHttpMethodsOnOneMappingProduceTwoOperations(): void
    {
        $operations = $this->at('multi');

        $this->assertCount(2, $operations);
        $this->assertSame(['get', 'post'], array_map(static fn (OA\Operation $op): string => (string) $op->method, $operations));
        // 同一路径两个动词：operationId 必须不同（它由方法 + URL 派生）。
        $this->assertSame(['get_op_multi', 'post_op_multi'], array_map(static fn (OA\Operation $op): string => (string) $op->operationId, $operations));
    }

    public function testSummaryDescriptionTagsAndFolderComeFromAttributes(): void
    {
        $operation = $this->single('get_item');

        $this->assertSame('读取条目', $operation->summary);
        $this->assertSame('详细说明', $operation->description);
        $this->assertSame(['甲'], $operation->tags);
        $this->assertSame('示例/分组', $operation->x['apifox-folder'] ?? null);
        $this->assertSame('读取条目', $operation->x['apifox-name'] ?? null);
    }

    public function testSummaryFallsBackToMethodNameAndOptionalExtensionsAreOmitted(): void
    {
        $operation = $this->single('no_params');

        $this->assertSame('noParameters', $operation->summary);
        // 没写 description / tags 的不该出现空的占位值。
        $this->assertNull($operation->description);
        $this->assertNull($operation->tags);
        $this->assertArrayNotHasKey('required-permissions', $operation->x);
    }

    public function testHeaderParametersComeBeforeQueryParameters(): void
    {
        $operation = $this->single('get_item');

        $this->assertIsArray($operation->parameters);
        $this->assertSame(
            [['X-Trace-Id', 'header'], ['page', 'query']],
            array_map(static fn (OA\Parameter $p): array => [(string) $p->name, (string) $p->in], $operation->parameters),
        );
    }

    public function testErrorResponsesFollowTheDeclaredCapabilities(): void
    {
        // 有查询参数 + 权限码 + 限流：400/403/422/429/500，且权限码意味着需要认证 → 401。
        $this->assertSame([200, 400, 401, 403, 422, 429, 500], $this->statuses($this->single('get_item')));

        // 公开、没有参数也没有请求体：只有「任何接口都可能发生」的 400 与 500。
        $this->assertSame([200, 400, 500], $this->statuses($this->single('no_params')));
    }

    public function testPermissionCodeImpliesBearerWhilePublicStaysOpen(): void
    {
        $secured = $this->single('get_item');
        $this->assertIsArray($secured->security);
        $this->assertSame('bearerAuth', $secured->security[0]->scheme);
        $this->assertSame(['order.read'], $secured->x['required-permissions'] ?? null);

        $this->assertNull($this->single('no_params')->security);
    }

    public function testExcludedMethodIsSkipped(): void
    {
        // 排除清单匹配的是「类名::方法名」，不是 URL path。
        $operations = $this->build(OperationFixture::class, ['*::noParameters']);

        $this->assertSame(
            ['get_op_get_item', 'get_op_multi', 'post_op_multi'],
            array_map(static fn (OA\Operation $op): string => (string) $op->operationId, $operations),
        );
    }

    /**
     * 按 URL path 取出夹具里对应的 operation（夹具同时声明了多个方法）。
     *
     * @return list<OA\Operation>
     */
    private function at(string $path): array
    {
        return array_values(array_filter(
            $this->build(OperationFixture::class),
            static fn (OA\Operation $op): bool => $op->path === '/op/' . $path,
        ));
    }

    private function single(string $path): OA\Operation
    {
        $operations = $this->at($path);
        $this->assertCount(1, $operations, sprintf('%s 应当装配出一个 operation。', $path));

        return $operations[0];
    }

    /**
     * @param class-string $controller
     * @param list<string> $excluded
     * @return list<OA\Operation>
     */
    private function build(string $controller, array $excluded = []): array
    {
        return $this->builder($excluded)->build(new ReflectionClass($controller));
    }

    /**
     * @return list<int>
     */
    private function statuses(OA\Operation $operation): array
    {
        $responses = $operation->responses;
        $this->assertIsArray($responses);

        return array_map(static fn (OA\Response $response): int => (int) $response->response, $responses);
    }

    /**
     * @param list<string> $excluded
     */
    private function builder(array $excluded = []): OperationBuilder
    {
        $schemas = new DTOSchemaFactory();
        $translator = new SchemaFragmentTranslator();
        $responses = new ApiResponseFactory(new Config(['response' => ['response_dto' => ApiResponseDTO::class]]));

        return new OperationBuilder(
            new RequestBuilder($schemas, $translator),
            new ResponseBuilder(
                $schemas,
                $translator,
                new ReturnTypeResolver($schemas),
                new ResponseEncoderResolver(new JsonEncoderContainer(), $responses),
                new ErrorResponseCatalog($translator),
                ApiResponseDTO::class,
            ),
            new ControllerScanner($excluded),
            new SecurityResolver(new AccessDeclarationReader()),
            new RateLimitRuleReader(),
        );
    }
}

#[ApiFolder('示例/分组')]
#[Controller(prefix: '/op')]
final class OperationFixture
{
    #[ApiHeader(name: 'X-Trace-Id', description: '追踪标识')]
    #[PublicAccess]
    #[ApiOperation(summary: '读取条目', tags: ['甲'], description: '详细说明')]
    #[RequirePermission('order.read')]
    #[RateLimit(name: 'op_read', capacity: 5, refill: 1)]
    #[GetMapping(path: 'get_item')]
    public function getItem(#[FromQuery] int $page = 1): OperationItemDTO
    {
        return new OperationItemDTO();
    }

    #[PublicAccess]
    #[GetMapping(path: 'no_params')]
    public function noParameters(): void
    {
    }

    #[PublicAccess]
    #[RequestMapping(path: 'multi', methods: ['GET', 'POST'])]
    public function multi(): void
    {
    }
}

final readonly class OperationItemDTO extends AbstractDTO
{
    public function __construct(
        #[FieldDescription('名称')]
        public string $name = '',
    ) {
    }
}

#[Controller(prefix: '')]
final class EmptyPrefixFixture
{
    #[GetMapping(path: 'x')]
    public function x(): void
    {
    }
}

#[Controller(prefix: '/op')]
final class EmptyMappingPathFixture
{
    #[GetMapping]
    public function x(): void
    {
    }
}

#[Controller(prefix: '/op')]
final class AbsoluteMappingPathFixture
{
    #[GetMapping(path: '/absolute')]
    public function x(): void
    {
    }
}
