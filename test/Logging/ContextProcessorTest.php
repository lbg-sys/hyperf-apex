<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Logging;

use DateTimeImmutable;
use Hyperf\Apex\Http\ClientIpResolver;
use Hyperf\Apex\Http\IpRuleMatcher;
use Hyperf\Apex\Logging\ContextProcessor;
use Hyperf\Config\Config;
use Hyperf\Context\RequestContext;
use Hyperf\HttpMessage\Server\Request;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 日志上下文富化的契约。
 *
 * 每条日志都带上 process_id / coroutine_id；有请求时再带 request_id、method、path、client_ip。
 * 这些字段是把分散日志串成一次请求调用链的关键。
 */
final class ContextProcessorTest extends TestCase
{
    public function testEnrichesWithRequestContext(): void
    {
        $record = new LogRecord(new DateTimeImmutable(), 'app', Level::Info, 'msg', ['existing' => 'v']);
        RequestContext::set($this->request(['X-Request-Id' => 'req-1'], '10.0.0.1'));

        $result = $this->processor()($record);

        $this->assertSame(getmypid(), $result->context['process_id']);
        $this->assertArrayHasKey('coroutine_id', $result->context);
        $this->assertSame('req-1', $result->context['request_id']);
        $this->assertSame('POST', $result->context['method']);
        $this->assertSame('/api/items', $result->context['path']);
        $this->assertSame('10.0.0.1', $result->context['client_ip']);
        // 记录自身的 context 原样保留。
        $this->assertSame('v', $result->context['existing']);
    }

    public function testOmitsRequestIdWhenHeaderAbsent(): void
    {
        $record = new LogRecord(new DateTimeImmutable(), 'app', Level::Info, 'msg');
        RequestContext::set($this->request(remoteAddr: '10.0.0.1'));

        $result = $this->processor()($record);

        $this->assertArrayNotHasKey('request_id', $result->context);
    }

    public function testOmitsClientIpWhenUnresolvable(): void
    {
        $record = new LogRecord(new DateTimeImmutable(), 'app', Level::Info, 'msg');
        RequestContext::set($this->request());

        $result = $this->processor()($record);

        $this->assertArrayNotHasKey('client_ip', $result->context);
    }

    private function processor(): ContextProcessor
    {
        return new ContextProcessor(new ClientIpResolver(new Config([]), new IpRuleMatcher()));
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(array $headers = [], ?string $remoteAddr = null): ServerRequestInterface
    {
        $request = new Request('POST', '/api/items');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($remoteAddr !== null) {
            $request = $request->withServerParams(['remote_addr' => $remoteAddr]);
        }

        return $request;
    }
}
