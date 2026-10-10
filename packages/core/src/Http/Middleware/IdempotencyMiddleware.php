<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\Middleware;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Http\HandlerMethodResolver;
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
    public const HEADER = 'Idempotency-Key';

    public function __construct(
        private readonly HandlerMethodResolver $handlerMethods,
        private readonly IdempotencyCallerResolver $callers,
        private readonly IdempotencyRequestHasher $hasher,
        private readonly IdempotencyService $service,
        private readonly ApiResponseFactory $responses,
        private readonly ResponseEncoderResolver $encoders,
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
            ->withHeader(self::HEADER, $requestId)
            ->withHeader('Idempotency-Replayed', 'false');
    }

    private function requestId(ServerRequestInterface $request, Idempotent $declaration): string
    {
        $requestId = $declaration->requestIdField === null
            ? $request->getHeaderLine(self::HEADER)
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
            ->withHeader(self::HEADER, $requestId)
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
     * 写回失败只说明本次认领已被接管（执行超时后又被其它请求接管），
     * 此时接管者才是权威结果：记一条 warning 即可，不能抛错 ——
     * 业务副作用已经发生，把已经执行完的请求变成 500 只会掩盖真正的问题。
     */
    private function store(
        IdempotencyClaimDTO $claim,
        IdempotencyStatus $status,
        int $httpStatus,
        string $contentType,
        string $body,
    ): void {
        if ($this->service->complete($claim, $status, $httpStatus, $contentType, $body)) {
            return;
        }

        $this->logger->warning('幂等记录已被其他请求接管，本次执行结果未保存。', [
            'record_id' => $claim->record->id,
        ]);
    }
}
