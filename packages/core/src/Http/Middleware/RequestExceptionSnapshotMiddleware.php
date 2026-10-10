<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\Middleware;

use Hyperf\Apex\Exception\ExpectedException;
use Hyperf\Apex\Support\SensitiveDataFilter;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Logger\LoggerFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class RequestExceptionSnapshotMiddleware extends AbstractMiddleware
{
    private readonly LoggerInterface $logger;

    public function __construct(
        LoggerFactory $loggerFactory,
        private readonly ConfigInterface $config,
    ) {
        $this->logger = $loggerFactory->get('exception', 'error');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $throwable) {
            if ($this->isServerError($throwable) && $this->shouldCapture($request)) {
                $this->logger->error('HTTP request exception snapshot.', [
                    'exception' => $throwable,
                    'request_parameters' => $this->serializeParameters($request),
                ]);
            }

            throw $throwable;
        }
    }

    /**
     * 只有预期外的异常才进错误日志，判定直接复用 ExpectedException。
     *
     * 不在这里另写一套分类：口径的唯一来源是 AppExceptionHandler 的分支。路由不存在
     * （NotFoundHttpException，扫描器打得最多的一类请求）、资源不存在、校验失败，以及所有
     * BusinessException 都会被异常处理器转成带错误码的业务响应 —— 写进错误日志只会冲掉
     * 真正的 500，把 error 日志从「告警信号」降级成「噪音」。这些请求仍由 RequestTimingMiddleware
     * 带上 status_code 记入 access 日志，可查性没有损失。
     *
     * 注意 BusinessException 的 5xx（如用 INTERNAL_ERROR 包装的配置／数据问题）同样不再留痕：
     * 它的语义是「业务失败」而不是「未捕获异常」。那类问题要靠业务侧自检或 access 日志发现。
     */
    private function isServerError(Throwable $throwable): bool
    {
        return ! ExpectedException::matches($throwable);
    }

    private function shouldCapture(ServerRequestInterface $request): bool
    {
        if (! (bool) $this->config->get('request_exception_snapshot.enabled', false)) {
            return false;
        }

        $path = $request->getUri()->getPath();
        $blacklist = $this->patterns('blacklist');
        foreach ($blacklist as $pattern) {
            if (fnmatch($pattern, $path)) {
                return false;
            }
        }

        $whitelist = $this->patterns('whitelist');
        if ($whitelist === []) {
            return true;
        }
        foreach ($whitelist as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    private function patterns(string $name): array
    {
        $patterns = $this->config->get('request_exception_snapshot.' . $name, []);
        if (! is_array($patterns)) {
            return [];
        }
        return array_values(array_filter($patterns, static fn (mixed $pattern): bool => is_string($pattern) && trim($pattern) !== ''));
    }

    private function serializeParameters(ServerRequestInterface $request): string
    {
        $parameters = [
            'query' => SensitiveDataFilter::filter($request->getQueryParams()),
            'body' => SensitiveDataFilter::filter($request->getParsedBody()),
        ];
        $encoded = json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return '[unserializable]';
        }

        $maxLength = max(256, (int) $this->config->get('request_exception_snapshot.max_length', 8192));
        if (strlen($encoded) <= $maxLength) {
            return $encoded;
        }
        return mb_strcut($encoded, 0, $maxLength, 'UTF-8') . '...[truncated]';
    }
}
