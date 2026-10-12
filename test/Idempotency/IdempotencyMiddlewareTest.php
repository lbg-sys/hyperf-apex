<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Idempotency;

use ArrayObject;
use FastRoute\Dispatcher;
use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Http\HandlerMethodResolver;
use Hyperf\Apex\Http\Response\ApiResponseDTO;
use Hyperf\Apex\Http\Response\ApiResponseFactory;
use Hyperf\Apex\Http\Response\ResponseEncoderResolver;
use Hyperf\Apex\Idempotency\Attribute\Idempotent;
use Hyperf\Apex\Idempotency\IdempotencyCallerResolver;
use Hyperf\Apex\Idempotency\IdempotencyRequestHasher;
use Hyperf\Apex\Idempotency\IdempotencyService;
use Hyperf\Apex\Idempotency\Middleware\IdempotencyMiddleware;
use Hyperf\Apex\Security\Principal;
use Hyperf\Apex\Test\ApiDoc\Support\JsonEncoderContainer;
use Hyperf\Apex\Test\Idempotency\Support\FakePrincipalContext;
use Hyperf\Apex\Test\Idempotency\Support\InMemoryIdempotencyRecordRepository;
use Hyperf\Config\Config;
use Hyperf\Context\ResponseContext;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Server\Response;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\HttpServer\Router\Handler;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;

/**
 * HTTP 幂等中间件。
 *
 * 钉住两条主线：首次请求执行并把结果落库（响应带 Idempotency-Key / Idempotency-Replayed: false），
 * 重放不执行、按落库的结果回放（Idempotency-Replayed: true）；请求 ID 的合法性（格式、
 * 来源头还是字段）在进业务逻辑前校验。
 *
 * 幂等的 claim / complete / fencing 语义在 IdempotencyServiceTest 里，这里只管 HTTP 编排。
 */
final class IdempotencyMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        // replay() 走 ResponseContext::get()（非空安全），先放一个响应进去。
        ResponseContext::set(new Response());
    }

    public function testUnmatchedRoutePassesThrough(): void
    {
        $response = $this->middleware(new InMemoryIdempotencyRecordRepository())
            ->process($this->request('POST', null), $this->handler());

        $this->assertFalse($response->hasHeader(Idempotent::HEADER));
    }

    public function testMethodWithoutDeclarationPassesThrough(): void
    {
        $response = $this->middleware(new InMemoryIdempotencyRecordRepository())
            ->process($this->request('POST', $this->dispatched('plain')), $this->handler());

        $this->assertFalse($response->hasHeader(Idempotent::HEADER));
    }

    public function testNonPostIsRejected(): void
    {
        // 幂等只对「会写数据」的 POST 有意义，声明错了要在运行时立刻挑明。
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('只允许用于 POST 接口');

        $this->middleware(new InMemoryIdempotencyRecordRepository())
            ->process($this->request('GET', $this->dispatched('create')), $this->handler());
    }

    public function testInvalidRequestIdIsRejected(): void
    {
        try {
            $this->middleware(new InMemoryIdempotencyRecordRepository())->process(
                $this->request('POST', $this->dispatched('create'), [Idempotent::HEADER => 'bad']),
                $this->handler(),
            );
            $this->fail('不合法的请求 ID 应当被拒绝。');
        } catch (BusinessException $exception) {
            $this->assertSame(ErrorCode::INVALID_ARGUMENT->value, $exception->getErrorCode());
            $this->assertStringContainsString('8-128 位', $exception->getMessage());
        }
    }

    public function testFirstRequestExecutesAndMarksSuccess(): void
    {
        $counter = new ArrayObject(['calls' => 0]);
        $response = $this->middleware(new InMemoryIdempotencyRecordRepository())->process(
            $this->request('POST', $this->dispatched('create'), [Idempotent::HEADER => 'req-12345678']),
            $this->handler($counter),
        );

        $this->assertSame(1, $counter['calls']);
        $this->assertSame('req-12345678', $response->getHeaderLine(Idempotent::HEADER));
        $this->assertSame('false', $response->getHeaderLine('Idempotency-Replayed'));
    }

    public function testReplayReturnsTheStoredResponseWithoutReExecuting(): void
    {
        $repository = new InMemoryIdempotencyRecordRepository();
        $middleware = $this->middleware($repository);
        $request = $this->request('POST', $this->dispatched('create'), [Idempotent::HEADER => 'req-12345678']);

        $first = $middleware->process($request, $this->handler());

        // 第二次：同一个调用方 + 同一个 ID，中间件按落库结果回放，不再执行 handler。
        $counter = new ArrayObject(['calls' => 0]);
        $replay = $middleware->process($request, $this->handler($counter));

        $this->assertSame(0, $counter['calls'], '重放不该再执行 handler。');
        $this->assertSame((string) $first->getBody(), (string) $replay->getBody());
        $this->assertSame('true', $replay->getHeaderLine('Idempotency-Replayed'));
    }

    public function testRequestIdCanComeFromTheBody(): void
    {
        $response = $this->middleware(new InMemoryIdempotencyRecordRepository())->process(
            $this->request('POST', $this->dispatched('createFromBody'), body: ['request_id' => 'req-12345678']),
            $this->handler(),
        );

        $this->assertSame('req-12345678', $response->getHeaderLine(Idempotent::HEADER));
        $this->assertSame('false', $response->getHeaderLine('Idempotency-Replayed'));
    }

    public function testBodyRequestIdMustBeAString(): void
    {
        try {
            $this->middleware(new InMemoryIdempotencyRecordRepository())->process(
                $this->request('POST', $this->dispatched('createFromBody'), body: ['request_id' => 123]),
                $this->handler(),
            );
            $this->fail('请求字段不是字符串时应当被拒绝。');
        } catch (BusinessException $exception) {
            $this->assertStringContainsString('request_id 必须是字符串', $exception->getMessage());
        }
    }

    public function testBusinessExceptionIsStoredAndRethrown(): void
    {
        $middleware = $this->middleware(new InMemoryIdempotencyRecordRepository());
        $request = $this->request('POST', $this->dispatched('create'), [Idempotent::HEADER => 'req-12345678']);

        $throwing = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new BusinessException(ErrorCode::RESOURCE_CONFLICT, '状态冲突');
            }
        };

        try {
            $middleware->process($request, $throwing);
            $this->fail('业务异常应当重新抛出。');
        } catch (BusinessException $exception) {
            $this->assertSame(ErrorCode::RESOURCE_CONFLICT->value, $exception->getErrorCode());
        }

        // 失败结果已落库：重放返回同一份错误，而不是再次执行。
        $replay = $middleware->process($request, $this->handler());
        $this->assertSame('true', $replay->getHeaderLine('Idempotency-Replayed'));
        $this->assertSame(409, $replay->getStatusCode());
        $this->assertStringContainsString('状态冲突', (string) $replay->getBody());
    }

    public function testOversizedResponseBodyIsNotCached(): void
    {
        // 记录按 retention_seconds 保留 7 天：不设上限就是让一次大响应长期占库。
        // 超限时不入库响应体，但状态码照旧重放 —— 幂等的核心保证（不重复执行）不变。
        $middleware = $this->middleware(new InMemoryIdempotencyRecordRepository(), ['max_response_bytes' => 4]);
        $request = $this->request('POST', $this->dispatched('create'), [Idempotent::HEADER => 'req-12345678']);

        $first = $middleware->process($request, $this->handler());
        $this->assertSame('false', $first->getHeaderLine('Idempotency-Replayed'));

        $replay = $middleware->process($request, $this->handler());

        $this->assertSame('true', $replay->getHeaderLine('Idempotency-Replayed'));
        $this->assertSame($first->getStatusCode(), $replay->getStatusCode());
        $this->assertSame('', (string) $replay->getBody());
    }

    /**
     * @param array<string, mixed> $idempotencyConfig
     */
    private function middleware(InMemoryIdempotencyRecordRepository $repository, array $idempotencyConfig = []): IdempotencyMiddleware
    {
        $responses = new ApiResponseFactory(new Config(['response' => ['response_dto' => ApiResponseDTO::class]]));
        $context = new FakePrincipalContext();
        $context->set(new Principal(userId: 1, userType: 'user', username: 'alice', sessionId: 's-1'));

        return new IdempotencyMiddleware(
            new HandlerMethodResolver(),
            new IdempotencyCallerResolver($context),
            new IdempotencyRequestHasher(),
            new IdempotencyService($repository, new Config([
                'idempotency' => ['retention_seconds' => 604800, 'processing_timeout_seconds' => 300],
            ])),
            $responses,
            new ResponseEncoderResolver(new JsonEncoderContainer(), $responses),
            // 响应体入库上限：默认 256KB，需要时由用例覆盖（见 testOversizedResponseBodyIsNotCached）。
            new Config(['idempotency' => array_merge(['max_response_bytes' => 262144], $idempotencyConfig)]),
            new NullLogger(),
        );
    }

    /**
     * @param array<string, string> $headers
     * @param null|array<string, mixed> $body
     */
    private function request(string $method, ?Dispatched $dispatched, array $headers = [], ?array $body = null): ServerRequestInterface
    {
        $request = new Request($method, '/fixture');
        if ($dispatched !== null) {
            $request = $request->withAttribute(Dispatched::class, $dispatched);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $request;
    }

    private function dispatched(string $method): Dispatched
    {
        return new Dispatched([
            Dispatcher::FOUND,
            new Handler([IdempotentFixtureController::class, $method], '/fixture'),
            [],
        ]);
    }

    private function handler(?ArrayObject $counter = null): RequestHandlerInterface
    {
        return new class($counter) implements RequestHandlerInterface {
            public function __construct(private readonly ?ArrayObject $counter)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                if ($this->counter !== null) {
                    $this->counter['calls'] = ($this->counter['calls'] ?? 0) + 1;
                }

                return (new Response())->withBody(new SwooleStream('{"ok":true}'));
            }
        };
    }
}

final class IdempotentFixtureController
{
    #[Idempotent]
    public function create(): void
    {
    }

    #[Idempotent(requestIdField: 'request_id')]
    public function createFromBody(): void
    {
    }

    public function plain(): void
    {
    }
}
