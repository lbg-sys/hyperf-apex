<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Http;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Http\Response\ApiResponseDTO;
use Hyperf\Apex\Http\Response\ApiResponseFactory;
use Hyperf\Apex\Http\Response\Encoder\JsonResponseEncoder;
use Hyperf\Apex\Http\Response\ResponseEncoderResolver;
use Hyperf\Apex\Http\Response\ResponseTransformationContext;
use Hyperf\Apex\Http\Response\Transformer\ApiResponseTransformer;
use Hyperf\Config\Config;
use Hyperf\Context\ResponseContext;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Server\Response as PsrResponse;
use Hyperf\HttpServer\Response;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

/**
 * 信封 → HTTP 响应的装配契约。
 *
 * 关键一条：**响应体里的业务状态码必须反映到 HTTP 状态码上**。控制器直接返回信封时
 * （`ApiResponseDTO::make(ErrorCode::RESOURCE_NOT_FOUND)`），此前一律是 200 ——
 * 而文档是按 `ErrorCode::getHttpStatus()` 生成的，监控的错误率与客户端的重试策略也都按
 * HTTP 状态工作，三者会一起错。
 */
final class ApiResponseTransformerTest extends TestCase
{
    protected function setUp(): void
    {
        // HttpResponseInterface 的实现每次都从 ResponseContext 取响应对象。
        ResponseContext::set(new PsrResponse());
    }

    public function testErrorEnvelopeDecidesTheHttpStatus(): void
    {
        $response = $this->transform(ApiResponseDTO::make(ErrorCode::RESOURCE_NOT_FOUND));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(ErrorCode::RESOURCE_NOT_FOUND->value, $this->json($response)['code']);
    }

    public function testAuthenticationErrorCarriesItsStatus(): void
    {
        $response = $this->transform(ApiResponseDTO::make(ErrorCode::UNAUTHENTICATED));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testSuccessEnvelopeStaysOk(): void
    {
        $response = $this->transform(ApiResponseDTO::success(['id' => 1]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPlainValueIsWrappedIntoSuccessEnvelope(): void
    {
        $response = $this->transform(['id' => 1]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['id' => 1], $this->json($response)['data']);
    }

    public function testUnknownBusinessCodeKeepsTheStatusUntouched(): void
    {
        // 应用自定义的错误码不在 ErrorCode 里：不猜状态码，保持响应对象原本的状态。
        $response = $this->transform(ApiResponseDTO::make(987654, '自定义错误'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(987654, $this->json($response)['code']);
    }

    private function transform(mixed $value): PsrResponseInterface
    {
        return $this->transformer()->transform(
            new ResponseTransformationContext($value, null, new Request('GET', '/fixture')),
        );
    }

    private function transformer(): ApiResponseTransformer
    {
        $factory = new ApiResponseFactory(new Config(['response' => ['response_dto' => ApiResponseDTO::class]]));
        // 编码器按响应 DTO 上的 #[ResponseEncoder] 解析，容器只需给出这个实现。
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

        return new ApiResponseTransformer(
            $factory,
            new ResponseEncoderResolver($container, $factory),
            new Response(new PsrResponse()),
        );
    }

    /** @return array<string, mixed> */
    private function json(PsrResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }
}
