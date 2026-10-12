<?php

declare(strict_types=1);

namespace Hyperf\Apex\Exception\Handler;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Enum\HttpStatus;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Exception\Contract\UnhandledExceptionReporterInterface;
use Hyperf\Apex\Exception\ExceptionStatus;
use Hyperf\Apex\Exception\ExpectedException;
use Hyperf\Apex\Http\HandlerMethodResolver;
use Hyperf\Apex\Http\Response\ApiResponseFactory;
use Hyperf\Apex\Http\Response\Encoder\JsonResponseEncoder;
use Hyperf\Apex\Http\Response\ResponseEncoderResolver;
use Hyperf\Apex\Logging\LoggerChannels;
use Hyperf\Context\RequestContext;
use Hyperf\Database\Model\ModelNotFoundException;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\ExceptionHandler\Formatter\FormatterInterface;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Validation\ValidationException;
use LogicException;
use Psr\Log\LoggerInterface;
use Swow\Psr7\Message\ResponsePlusInterface;
use Throwable;

class AppExceptionHandler extends ExceptionHandler
{
    private readonly LoggerInterface $logger;

    public function __construct(
        LoggerFactory $loggerFactory,
        private readonly FormatterInterface $formatter,
        private readonly ApiResponseFactory $responses,
        private readonly ResponseEncoderResolver $encoders,
        private readonly JsonResponseEncoder $fallbackEncoder,
        private readonly HandlerMethodResolver $handlerMethods,
        /*
         * 可空：异常处理器是所有请求的兜底，绑定不到告警实现时也必须能构造出来。
         * 少了它只是少一次告警，绝不能因此让 500 响应也发不出去。
         */
        private readonly ?UnhandledExceptionReporterInterface $unhandledReporter = null,
    ) {
        $this->logger = LoggerChannels::resolve($loggerFactory, 'exception', 'error');
    }

    public function handle(Throwable $throwable, ResponsePlusInterface $response): ResponsePlusInterface
    {
        $this->stopPropagation();

        if (ExpectedException::matches($throwable)) {
            return $this->handleExpected($throwable, $response);
        }

        return $this->handleUnhandled($throwable, $response);
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }

    /**
     * 设计好的失败路径：转成带错误码的业务响应，不写 error 日志、不触发未捕获异常告警。
     *
     * 「哪些异常算预期内」由 ExpectedException 统一定义，这里只负责各类的响应体。
     * 不要在这条路径上加「顺便记一条日志」之类的判断：日志与告警的归属只由 matches() 决定，
     * 靠分支顺序隐式表达的话，谁在中间插一个新类型都会悄悄改变日志行为。
     *
     * 状态码一律取自 ExceptionStatus，不在分支里写死：中间件也要靠同一份映射反推异常请求的
     * 状态码（它拿不到这里生成的响应），写死就等于把同一份映射埋了两遍。
     */
    private function handleExpected(Throwable $throwable, ResponsePlusInterface $response): ResponsePlusInterface
    {
        if ($throwable instanceof BusinessException) {
            return $this->createApiResponse(
                $response,
                ExceptionStatus::resolve($throwable),
                $throwable->getErrorCode(),
                $throwable->getMessage(),
                $throwable->getData()
            );
        }

        if ($throwable instanceof ValidationException) {
            return $this->createApiResponse(
                $response,
                ExceptionStatus::resolve($throwable),
                ErrorCode::VALIDATION_FAILED->value,
                ErrorCode::VALIDATION_FAILED->getMessage(),
                ['errors' => $throwable->errors()]
            );
        }

        if ($throwable instanceof ModelNotFoundException) {
            return $this->createApiResponse(
                $response,
                ExceptionStatus::resolve($throwable),
                ErrorCode::RESOURCE_NOT_FOUND->value,
                ErrorCode::RESOURCE_NOT_FOUND->getMessage()
            );
        }

        if ($throwable instanceof HttpException) {
            $statusCode = ExceptionStatus::resolve($throwable);
            $errorCode = ErrorCode::fromHttpStatus($statusCode);

            /*
             * 不回显 HttpException 自带的 message。这一类的抛出方是框架与 HTTP 管道本身：
             * 路由不存在、方法不允许、请求体解析失败，文案是内部技术细节，有的甚至不是句子
             * （如 MethodNotAllowedHttpException 的 message 是 "Allow: GET, POST"，那是响应头的值）。
             * 需要给客户端看文案的失败一律走 BusinessException，它同时带上与文档对得上的错误码。
             */
            return $this->createApiResponse(
                $response,
                $statusCode,
                $errorCode->value,
                HttpStatus::tryFrom($statusCode)?->getMessage() ?? 'HTTP 请求错误'
            );
        }

        /*
         * 理论上不可达：matches() 已经把范围收窄成上面四类。真走到这里说明两个清单分了叉，
         * 必须立刻暴露 —— 静默按 500 处理会变成「日志里什么都没有、客户端却拿到 500」，
         * 那是最难查的一种组合。
         */
        throw new LogicException(sprintf('%s 被判为预期内异常，却没有对应的响应分支。', $throwable::class));
    }

    /**
     * 未捕获异常：写 error 日志、上报告警，并给客户端 500。
     */
    private function handleUnhandled(Throwable $throwable, ResponsePlusInterface $response): ResponsePlusInterface
    {
        $this->logger->error($this->formatter->format($throwable), [
            'exception' => $throwable,
        ]);

        $this->reportUnhandled($throwable);

        return $this->createApiResponse(
            $response,
            HttpStatus::INTERNAL_SERVER_ERROR->value,
            ErrorCode::INTERNAL_ERROR->value,
            ErrorCode::INTERNAL_ERROR->getMessage()
        );
    }

    /**
     * 未捕获异常上报告警。
     *
     * 整段包在 try 里：告警上报的任何问题都不能把「返回 500」这件事也搞砸，
     * 否则一个原始故障会变成「客户端拿到空响应」这种更难排查的现象。
     */
    private function reportUnhandled(Throwable $throwable): void
    {
        if (! $this->unhandledReporter instanceof UnhandledExceptionReporterInterface) {
            return;
        }

        try {
            $request = RequestContext::getOrNull();
            $this->unhandledReporter->report($throwable, $request?->getUri()->getPath());
        } catch (Throwable $exception) {
            $this->logger->error('上报未捕获异常告警失败。', ['exception' => $exception]);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createApiResponse(
        ResponsePlusInterface $response,
        int $httpStatus,
        int $errorCode,
        string $message,
        array $data = []
    ): ResponsePlusInterface {
        $request = RequestContext::getOrNull();
        $method = $request === null ? null : $this->handlerMethods->resolve($request);
        $encoder = $this->encoders->resolve($method);
        try {
            $body = $encoder->encode($this->responses->make($errorCode, $message, $data));
        } catch (Throwable $exception) {
            $this->logger->error('Failed to encode exception response.', [
                'exception' => $exception,
            ]);

            $httpStatus = HttpStatus::INTERNAL_SERVER_ERROR->value;
            $encoder = $this->fallbackEncoder;
            $body = $encoder->encode($this->responses->make(ErrorCode::INTERNAL_ERROR));
        }

        return $response
            ->setStatus($httpStatus)
            ->setHeader('content-type', $encoder->contentType())
            ->setBody(new SwooleStream($body));
    }
}
