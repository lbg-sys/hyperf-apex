<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Http;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Http\Response\ApiResponseDTO;
use PHPUnit\Framework\TestCase;

final class ApiResponseDTOTest extends TestCase
{
    public function testSuccessResponseStructure(): void
    {
        $dto = ApiResponseDTO::success(['id' => 123, 'name' => 'test']);

        $this->assertSame(0, $dto->code);
        $this->assertSame('成功', $dto->message);
        $this->assertSame(['id' => 123, 'name' => 'test'], $dto->data);

        $json = json_encode($dto);
        $this->assertIsString($json);
        $decoded = json_decode($json, true);

        $this->assertSame(0, $decoded['code']);
        $this->assertSame('成功', $decoded['message']);
        $this->assertSame(['id' => 123, 'name' => 'test'], $decoded['data']);
    }

    public function testSuccessWithoutDataKeepsNull(): void
    {
        // 无业务数据的接口（void 返回类型）在文档里是 `data: null`；
        // 给 `[]` 会让「没有数据」与「数据是空列表」在响应里长得一模一样。
        $dto = ApiResponseDTO::success();

        $this->assertNull($dto->data);

        $json = json_encode($dto);
        $this->assertIsString($json);
        $this->assertNull(json_decode($json, true)['data']);
    }

    public function testMakeWithError(): void
    {
        $dto = ApiResponseDTO::make(ErrorCode::INVALID_ARGUMENT, '参数无效', ['field' => 'username']);

        $this->assertSame(ErrorCode::INVALID_ARGUMENT->value, $dto->code);
        $this->assertSame('参数无效', $dto->message);
        $this->assertSame(['field' => 'username'], $dto->data);
    }
}
