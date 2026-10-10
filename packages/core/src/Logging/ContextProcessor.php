<?php

declare(strict_types=1);

namespace Hyperf\Apex\Logging;

use Hyperf\Apex\Http\ClientIpResolver;
use Hyperf\Context\RequestContext;
use Hyperf\Engine\Coroutine;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class ContextProcessor implements ProcessorInterface
{
    public function __construct(private readonly ClientIpResolver $clientIpResolver)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = [
            'process_id' => getmypid(),
            'coroutine_id' => Coroutine::id(),
        ];

        $request = RequestContext::getOrNull();
        if ($request !== null) {
            $requestId = trim($request->getHeaderLine('X-Request-Id'));
            if ($requestId !== '') {
                $context['request_id'] = $requestId;
            }

            $context['method'] = $request->getMethod();
            $context['path'] = $request->getUri()->getPath();

            $clientIp = $this->clientIpResolver->resolve($request);
            if ($clientIp !== null) {
                $context['client_ip'] = $clientIp;
            }
        }

        return $record->with(context: array_merge($context, $record->context));
    }
}
