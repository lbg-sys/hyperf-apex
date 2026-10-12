<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\Response;

use Hyperf\Apex\DTO\Attribute\FieldDescription;
use Hyperf\Apex\DTO\Attribute\Required;
use Hyperf\Apex\DTO\Attribute\ResponsePayload;
use Hyperf\Apex\DTO\DTOJsonableTrait;
use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Http\Response\Attribute\ResponseEncoder;
use Hyperf\Apex\Http\Response\Contract\ApiResponseInterface;
use Hyperf\Apex\Http\Response\Encoder\JsonResponseEncoder;
use JsonSerializable;

#[ResponseEncoder(JsonResponseEncoder::class)]
final readonly class ApiResponseDTO implements ApiResponseInterface, JsonSerializable
{
    use DTOJsonableTrait;

    public function __construct(
        #[FieldDescription('业务状态码，0 表示成功')]
        public int $code,
        #[FieldDescription('提示信息')]
        public string $message,
        // 默认 null 而不是空数组：无业务数据的接口（void 返回类型）在文档里是 `data: null`，
        // 给 `[]` 会让「没有数据」与「数据是空列表」在响应里长得一模一样。
        #[FieldDescription('业务数据'), Required, ResponsePayload]
        public mixed $data = null,
    ) {
    }

    public function code(): int
    {
        return $this->code;
    }

    public static function success(mixed $data = null, string $message = '成功'): self
    {
        return new self(ErrorCode::SUCCESS->value, $message, $data);
    }

    public static function make(ErrorCode|int $code, ?string $message = null, mixed $data = []): self
    {
        $codeValue = $code instanceof ErrorCode ? $code->value : $code;
        return new self($codeValue, $message ?? ($code instanceof ErrorCode ? $code->getMessage() : ''), $data);
    }
}
