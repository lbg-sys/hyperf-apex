<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Exception;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Exception\ExpectedException;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\HttpMessage\Exception\ServerErrorHttpException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 「预期内失败路径」的判定口径。
 *
 * 它同时决定了两件事：客户端拿到什么响应、以及这次失败写不写 error 日志与告警。
 * 判定范围放得太宽，真实故障就会静默（只剩 access 日志）；放得太窄，扫描器打的 404
 * 会把异常指标顶到永远不归零。
 */
final class ExpectedExceptionTest extends TestCase
{
    public function testBusinessExceptionIsExpected(): void
    {
        $this->assertTrue(ExpectedException::matches(new BusinessException(ErrorCode::BUSINESS_ERROR, '业务拒绝')));
    }

    public function testClientHttpExceptionIsExpected(): void
    {
        // 4xx 由 access 日志与 status_class 承担可观测性，不该再算一次异常。
        $this->assertTrue(ExpectedException::matches(new HttpException(404, 'Not Found')));
    }

    public function testServerHttpExceptionIsNotExpected(): void
    {
        // 5xx 是服务端故障：必须走未捕获路径（记 error 日志 + 上报告警），
        // 否则真实故障只剩一条 access 日志，大屏上的异常数也不会动。
        $this->assertFalse(ExpectedException::matches(new ServerErrorHttpException('内部错误')));
        $this->assertFalse(ExpectedException::matches(new HttpException(503, 'Service Unavailable')));
    }

    public function testRuntimeExceptionIsNotExpected(): void
    {
        $this->assertFalse(ExpectedException::matches(new RuntimeException('崩了')));
    }
}
