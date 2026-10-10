<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\ApiDoc\Attribute\ApiError;
use Hyperf\Apex\ApiDoc\Builder\ErrorResponseCatalog;
use Hyperf\Apex\ApiDoc\Builder\ResponseBuilder;
use Hyperf\Apex\ApiDoc\Builder\ReturnTypeResolver;
use Hyperf\Apex\ApiDoc\Compiler\SchemaFragmentTranslator;
use Hyperf\Apex\ApiDoc\DTOSchemaFactory;
use Hyperf\Apex\DTO\Attribute\ResponsePayload;
use Hyperf\Apex\DTO\DTOJsonableTrait;
use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Http\Response\ApiResponseDTO;
use Hyperf\Apex\Http\Response\ApiResponseFactory;
use Hyperf\Apex\Http\Response\Contract\ApiResponseInterface;
use Hyperf\Apex\Http\Response\Encoder\JsonResponseEncoder;
use Hyperf\Apex\Http\Response\ResponseEncoderResolver;
use Hyperf\Apex\Test\ApiDoc\Endpoint\Controller\FixtureController;
use Hyperf\Config\Config;
use OpenApi\Spec as OA;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * 响应信封的契约。
 *
 * 信封里哪个属性承载业务数据由 `#[ResponsePayload]` 唯一标记，且**恰好一个**：
 * 没有标记无从替换、多个标记无法判断替换谁，两者都会让所有接口的 data 结构静默走偏，
 * 所以在生成期直接报错，而不是挑一个凑合。
 */
final class ResponseBuilderTest extends TestCase
{
    public function testMissingPayloadMarkerIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must have exactly one property marked');

        $this->builder(NoPayloadResponseDTO::class)->success($this->endpointMethod());
    }

    public function testDuplicatePayloadMarkerIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must have exactly one property marked');

        $this->builder(TwoPayloadResponseDTO::class)->success($this->endpointMethod());
    }

    public function testPayloadPropertyIsReplacedByTheDeclaredReturnType(): void
    {
        $response = $this->builder(ApiResponseDTO::class)->success($this->endpointMethod());

        $properties = $this->propertiesByName($response);

        // data 由控制器方法的返回类型决定，属性自己推断出的 mixed 不该残留。
        $this->assertSame('#/components/schemas/FixtureItemDTO', $properties['data']->schema->ref);
        // 信封的另外两个字段仍按响应 DTO 的定义输出。
        $this->assertSame('integer', $properties['code']->schema->type);
        $this->assertSame('string', $properties['message']->schema->type);
        $this->assertSame('成功', $response->description);
    }

    public function testOnlyReachableErrorStatusesAreCompleted(): void
    {
        $builder = $this->builder(ApiResponseDTO::class);
        $method = $this->endpointMethod();
        $statuses = static fn (bool $auth, bool $perm, bool $input, bool $rate): array => array_map(
            static fn (OA\Response $response): int => (int) $response->response,
            $builder->errors($method, $auth, $perm, $input, $rate),
        );

        // 400/500 任何接口都可能发生；其余按「真实可达」推导，而不是「谁会抛」。
        $this->assertSame([400, 500], $statuses(false, false, false, false));
        $this->assertSame([400, 401, 500], $statuses(true, false, false, false));
        $this->assertSame([400, 403, 500], $statuses(false, true, false, false));
        $this->assertSame([400, 422, 500], $statuses(false, false, true, false));
        $this->assertSame([400, 429, 500], $statuses(false, false, false, true));
    }

    public function testApiErrorCodesAreDeduplicatedAndSorted(): void
    {
        $method = new ReflectionMethod(ErrorFixture::class, 'withApiErrors');
        $responses = $this->builder(ApiResponseDTO::class)->errors($method, false, false, false, false);

        // #[ApiError] 声明了 409、400（与恒有项重复）、404：去重且按状态码升序。
        $this->assertSame(
            [400, 404, 409, 500],
            array_map(static fn (OA\Response $response): int => (int) $response->response, $responses),
        );
    }

    /**
     * @return array<string, OA\Property>
     */
    private function propertiesByName(OA\Response $response): array
    {
        // Response::$content 是 MediaType 的列表（swagger-php 用 wrapList 统一成数组）。
        $content = $response->content;
        $this->assertIsArray($content);
        $schema = $content[0]->schema ?? null;
        $this->assertInstanceOf(OA\Schema::class, $schema, '成功响应应当有 JSON 结构。');
        $this->assertIsArray($schema->properties);

        $byName = [];
        foreach ($schema->properties as $property) {
            $this->assertInstanceOf(OA\Property::class, $property);
            $byName[$property->property] = $property;
        }

        return $byName;
    }

    private function endpointMethod(): ReflectionMethod
    {
        return new ReflectionMethod(FixtureController::class, 'getList');
    }

    /** @param class-string<ApiResponseInterface> $responseDtoClass */
    private function builder(string $responseDtoClass): ResponseBuilder
    {
        $schemas = new DTOSchemaFactory();
        $translator = new SchemaFragmentTranslator();
        $responses = new ApiResponseFactory(new Config(['response' => ['response_dto' => $responseDtoClass]]));
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                return new JsonResponseEncoder();
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        return new ResponseBuilder(
            $schemas,
            $translator,
            new ReturnTypeResolver($schemas),
            new ResponseEncoderResolver($container, $responses),
            new ErrorResponseCatalog($translator),
            $responseDtoClass,
        );
    }
}

/** 没有标记 #[ResponsePayload]：无从知道该把哪个属性换成返回类型。 */
final readonly class NoPayloadResponseDTO implements ApiResponseInterface
{
    use DTOJsonableTrait;

    public function __construct(
        public int $code,
        public string $message,
        public mixed $data = [],
    ) {
    }

    public static function success(mixed $data = [], string $message = '成功'): self
    {
        return new self(ErrorCode::SUCCESS->value, $message, $data);
    }

    public static function make(ErrorCode|int $code, ?string $message = null, mixed $data = []): self
    {
        return new self(
            $code instanceof ErrorCode ? $code->value : $code,
            $message ?? '错误',
            $data,
        );
    }
}

/** 声明了「只有本接口会出现」的错误：顺带验证去重与升序。 */
final class ErrorFixture
{
    #[ApiError(ErrorCode::RESOURCE_CONFLICT, ErrorCode::INVALID_ARGUMENT, ErrorCode::RESOURCE_NOT_FOUND)]
    public function withApiErrors(): void
    {
    }
}

/** 标记了两个 #[ResponsePayload]：无法判断替换谁。 */
final readonly class TwoPayloadResponseDTO implements ApiResponseInterface
{
    use DTOJsonableTrait;

    public function __construct(
        public int $code,
        public string $message,
        #[ResponsePayload]
        public mixed $data = [],
        #[ResponsePayload]
        public mixed $extra = [],
    ) {
    }

    public static function success(mixed $data = [], string $message = '成功'): self
    {
        return new self(ErrorCode::SUCCESS->value, $message, $data);
    }

    public static function make(ErrorCode|int $code, ?string $message = null, mixed $data = []): self
    {
        return new self(
            $code instanceof ErrorCode ? $code->value : $code,
            $message ?? '错误',
            $data,
        );
    }
}
