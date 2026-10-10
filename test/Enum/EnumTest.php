<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Enum;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Enum\HttpStatus;
use PHPUnit\Framework\TestCase;

final class EnumTest extends TestCase
{
    public function testErrorCodeHttpStatusMapping(): void
    {
        $this->assertSame(HttpStatus::OK, ErrorCode::SUCCESS->getHttpStatus());
        $this->assertSame(HttpStatus::BAD_REQUEST, ErrorCode::INVALID_ARGUMENT->getHttpStatus());
        $this->assertSame(HttpStatus::UNPROCESSABLE_ENTITY, ErrorCode::VALIDATION_FAILED->getHttpStatus());
        $this->assertSame(HttpStatus::UNAUTHORIZED, ErrorCode::UNAUTHENTICATED->getHttpStatus());
        $this->assertSame(HttpStatus::FORBIDDEN, ErrorCode::PERMISSION_DENIED->getHttpStatus());
        $this->assertSame(HttpStatus::NOT_FOUND, ErrorCode::RESOURCE_NOT_FOUND->getHttpStatus());
        $this->assertSame(HttpStatus::CONFLICT, ErrorCode::RESOURCE_CONFLICT->getHttpStatus());
        $this->assertSame(HttpStatus::TOO_MANY_REQUESTS, ErrorCode::TOO_MANY_REQUESTS->getHttpStatus());
        $this->assertSame(HttpStatus::INTERNAL_SERVER_ERROR, ErrorCode::INTERNAL_ERROR->getHttpStatus());
    }

    public function testErrorCodeHttpStatusCoversEveryCase(): void
    {
        /*
         * getHttpStatus() 是不带 default 分支的 match：新增 case 忘了补映射就会在运行期抛
         * UnhandledMatchError —— 而且往往是在构造 BusinessException 的过程中抛，现象与原因离得很远。
         * 把它钉在 CI 上，而不是等线上第一批请求来发现。
         *
         * 刻意不加 `default => 500`：那会把「漏了一个 4xx 映射」变成静默的 500，
         * 以「不会崩」为代价把错误码契约改坏。
         */
        foreach (ErrorCode::cases() as $case) {
            $this->assertInstanceOf(HttpStatus::class, $case->getHttpStatus(), $case->name);
        }
    }

    public function testErrorCodeFromHttpStatus(): void
    {
        $this->assertSame(ErrorCode::SUCCESS, ErrorCode::fromHttpStatus(200));
        $this->assertSame(ErrorCode::INVALID_ARGUMENT, ErrorCode::fromHttpStatus(400));
        $this->assertSame(ErrorCode::UNAUTHENTICATED, ErrorCode::fromHttpStatus(401));
        $this->assertSame(ErrorCode::PERMISSION_DENIED, ErrorCode::fromHttpStatus(403));
        $this->assertSame(ErrorCode::RESOURCE_NOT_FOUND, ErrorCode::fromHttpStatus(404));
        $this->assertSame(ErrorCode::RESOURCE_CONFLICT, ErrorCode::fromHttpStatus(409));
        $this->assertSame(ErrorCode::VALIDATION_FAILED, ErrorCode::fromHttpStatus(422));
        $this->assertSame(ErrorCode::TOO_MANY_REQUESTS, ErrorCode::fromHttpStatus(429));
        $this->assertSame(ErrorCode::INTERNAL_ERROR, ErrorCode::fromHttpStatus(500));
    }

    public function testHttpStatusClassChecks(): void
    {
        $this->assertTrue(HttpStatus::OK->isSuccessful());
        $this->assertFalse(HttpStatus::BAD_REQUEST->isSuccessful());

        $this->assertTrue(HttpStatus::BAD_REQUEST->isClientError());
        $this->assertTrue(HttpStatus::NOT_FOUND->isClientError());
        $this->assertFalse(HttpStatus::INTERNAL_SERVER_ERROR->isClientError());

        $this->assertTrue(HttpStatus::INTERNAL_SERVER_ERROR->isServerError());
        $this->assertTrue(HttpStatus::SERVICE_UNAVAILABLE->isServerError());
        $this->assertFalse(HttpStatus::OK->isServerError());
    }

    public function testEnumSerializationAndOptions(): void
    {
        $options = ErrorCode::getOptions();
        $this->assertIsArray($options);
        $this->assertNotEmpty($options);

        $first = $options[0];
        $this->assertArrayHasKey('key', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertSame(ErrorCode::SUCCESS->value, $first['key']);
        $this->assertSame('成功', $first['name']);

        $serialized = ErrorCode::SUCCESS->jsonSerialize();
        $this->assertSame(['key' => 0, 'name' => '成功'], $serialized);
    }
}
