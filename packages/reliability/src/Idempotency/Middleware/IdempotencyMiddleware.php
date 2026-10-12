<?php

declare(strict_types=1);

namespace Hyperf\Apex\Idempotency\Middleware;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Http\HandlerMethodResolver;
use Hyperf\Apex\Http\Middleware\AbstractMiddleware;
use Hyperf\Apex\Http\Response\ApiResponseFactory;
use Hyperf\Apex\Http\Response\ResponseEncoderResolver;
use Hyperf\Apex\Idempotency\Attribute\Idempotent;
use Hyperf\Apex\Idempotency\DTO\IdempotencyClaimDTO;
use Hyperf\Apex\Idempotency\Enum\IdempotencyStatus;
use Hyperf\Apex\Idempotency\IdempotencyCallerResolver;
use Hyperf\Apex\Idempotency\IdempotencyRequestHasher;
use Hyperf\Apex\Idempotency\IdempotencyService;
use Hyperf\Apex\Idempotency\Model\IdempotencyRecord;
use Hyperf\Context\ResponseContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpMessage\Stream\SwooleStream;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Throwable;

/**
 * 可选的 HTTP 幂等保护。
 *
 * 必须作为路由中间件挂在认证中间件之后；只有方法声明了 #[Idempotent] 才会生效。
 */
final class IdempotencyMiddleware extends AbstractMiddleware
{
    public function __construct(
        private readonly HandlerMethodResolver $handlerMethods,
        private readonly IdempotencyCallerResolver $callers,
        private readonly IdempotencyRequestHasher $hasher,
        private readonly IdempotencyService $service,
        private readonly ApiResponseFactory $responses,
        private readonly ResponseEncoderResolver $encoders,
        private readonly ConfigInterface $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $this->handlerMethods->resolve($request);
        if ($method === null) {
            return $handler->handle($request);
        }

        $attribute = $method->getAttributes(Idempotent::class)[0] ?? null;
        if ($attribute === null) {
            return $handler->handle($request);
        }
        if (strtoupper($request->getMethod()) !== 'POST') {
            throw new LogicException('#[Idempotent] 只允许用于 POST 接口。');
        }

        $declaration = $attribute->newInstance();
        $requestId = $this->requestId($request, $declaration);
        $endpoint = 'POST ' . $request->getUri()->getPath();
        $claim = $this->service->claim(
            $this->callers->resolve($request, $declaration),
            $endpoint,
            $requestId,
            $this->hasher->hash($request),
        );

        if (! $claim->acquired) {
            return $this->replay($claim->record, $requestId);
        }

        try {
            $response = $handler->handle($request);
        } catch (BusinessException $exception) {
            $this->storeBusinessException($claim, $method, $exception);
            throw $exception;
        } catch (Throwable $exception) {
            $this->storeInternalError($claim, $method);
            throw $exception;
        }

        $this->store(
            $claim,
            $response->getStatusCode() >= 500 ? IdempotencyStatus::FAILED : IdempotencyStatus::SUCCEEDED,
            $response->getStatusCode(),
            $response->getHeaderLine('content-type'),
            (string) $response->getBody(),
        );

        return $response
            ->withHeader(Idempotent::HEADER, $requestId)
            ->withHeader('Idempotency-Replayed', 'false');
    }

    private function requestId(ServerRequestInterface $request, Idempotent $declaration): string
    {
        $requestId = $declaration->requestIdField === null
            ? $request->getHeaderLine(Idempotent::HEADER)
            : $this->bodyRequestId($request, $declaration->requestIdField);
        $requestId = trim($requestId);

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $requestId) !== 1) {
            throw new BusinessException(
                ErrorCode::INVALID_ARGUMENT,
                '幂等请求 ID 必须是 8-128 位字母、数字或 . _ : -。'
            );
        }

        return $requestId;
    }

    private function bodyRequestId(ServerRequestInterface $request, string $field): string
    {
        $body = $request->getParsedBody();
        if (! is_array($body) || ! is_string($body[$field] ?? null)) {
            throw new BusinessException(ErrorCode::INVALID_ARGUMENT, sprintf('请求字段 %s 必须是字符串。', $field));
        }

        return $body[$field];
    }

    private function replay(IdempotencyRecord $record, string $requestId): ResponseInterface
    {
        return ResponseContext::get()
            ->withStatus($record->response_status ?? 500)
            ->withBody(new SwooleStream($record->response_body ?? ''))
            ->withHeader('content-type', $record->response_content_type ?? 'application/json')
            ->withHeader(Idempotent::HEADER, $requestId)
            ->withHeader('Idempotency-Replayed', 'true');
    }

    private function storeBusinessException(
        IdempotencyClaimDTO $claim,
        ReflectionMethod $method,
        BusinessException $exception,
    ): void {
        $encoder = $this->encoders->resolve($method);
        $this->store(
            $claim,
            IdempotencyStatus::FAILED,
            $exception->getHttpStatus()->value,
            $encoder->contentType(),
            $encoder->encode($this->responses->make(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getData(),
            )),
        );
    }

    private function storeInternalError(IdempotencyClaimDTO $claim, ReflectionMethod $method): void
    {
        $encoder = $this->encoders->resolve($method);
        $this->store(
            $claim,
            IdempotencyStatus::FAILED,
            ErrorCode::INTERNAL_ERROR->getHttpStatus()->value,
            $encoder->contentType(),
            $encoder->encode($this->responses->make(ErrorCode::INTERNAL_ERROR)),
        );
    }

    /**
     * 写回执行结果。
     *
     * 两种失败都不能抛错，业务副作用已经发生，把已经执行完的请求变成 500 只会掩盖真正的问题：
     * - 返回 false：本次认领已被接管（执行超时后又被其它请求接管），接管者才是权威结果，记 warning；
     * - 抛出异常：写库失败（连接池耗尽、主库切换、响应体超过列长）。此时记录会滞留在 processing，
     *   要等 `processing_timeout_seconds` 之后才可被接管 —— 这属于该记 error 的运行时故障。
     */
    private function store(
        IdempotencyClaimDTO $claim,
        IdempotencyStatus $status,
        int $httpStatus,
        string $contentType,
        string $body,
    ): void {
        $body = $this->cacheableBody($claim, $body);

        try {
            $completed = $this->service->complete($claim, $status, $httpStatus, $contentType, $body);
        } catch (Throwable $exception) {
            $this->logger->error('幂等记录写回失败，本次执行结果未保存。', [
                'record_id' => $claim->record->id,
                'exception' => $exception,
            ]);

            return;
        }

        if ($completed) {
            return;
        }

        $this->logger->warning('幂等记录已被其他请求接管，本次执行结果未保存。', [
            'record_id' => $claim->record->id,
        ]);
    }

    /**
     * 响应体入库上限（`idempotency.max_response_bytes`，默认 256KB；0 表示不限制）。
     *
     * 记录按 `retention_seconds` 保留（默认 7 天），不设上限时一次导出接口就会把整段响应写进库
     * 并持续占用空间（响应体列是 mediumText，16MB），超过列长还会让写回直接失败。
     * 超限时不入库响应体：重复请求仍能拿到相同的状态码、也不会重复执行 —— 幂等的核心保证不变，
     * 只是拿不到响应体，所以这里明确记一条 warning 而不是静默丢弃。
     */
    private function cacheableBody(IdempotencyClaimDTO $claim, string $body): string
    {
        $max = (int) $this->config->get('idempotency.max_response_bytes', 262144);
        if ($max <= 0 || strlen($body) <= $max) {
            return $body;
        }

        $this->logger->warning('幂等记录的响应体超过缓存上限，本次只保存状态码与响应类型。', [
            'record_id' => $claim->record->id,
            'bytes' => strlen($body),
            'max_bytes' => $max,
        ]);

        return '';
    }
}
