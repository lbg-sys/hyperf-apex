<?php

declare(strict_types=1);

namespace Hyperf\Apex\Exception;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Enum\HttpStatus;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class BusinessException extends RuntimeException
{
    private readonly int $errorCode;

    private readonly HttpStatus $httpStatus;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        ErrorCode|HttpStatus|int $errorCode = ErrorCode::BUSINESS_ERROR,
        ?string $message = null,
        ?Throwable $previous = null,
        ?HttpStatus $httpStatus = null,
        private readonly array $data = []
    ) {
        if ($errorCode instanceof HttpStatus) {
            $httpStatus ??= $errorCode;
            $errorCode = ErrorCode::fromHttpStatus($errorCode);
        }

        if ($errorCode instanceof ErrorCode) {
            if ($errorCode === ErrorCode::SUCCESS) {
                throw new InvalidArgumentException('Business exception cannot use the success error code.');
            }

            $message ??= $errorCode->getMessage();
            $httpStatus ??= $errorCode->getHttpStatus();
            $errorCode = $errorCode->value;
        } elseif ($errorCode <= 0) {
            $errorCode = ErrorCode::BUSINESS_ERROR->value;
        } elseif ($knownErrorCode = ErrorCode::tryFrom($errorCode)) {
            $message ??= $knownErrorCode->getMessage();
            $httpStatus ??= $knownErrorCode->getHttpStatus();
        } elseif ($knownHttpStatus = HttpStatus::tryFrom($errorCode)) {
            $httpStatus ??= $knownHttpStatus;
            $errorCode = ErrorCode::fromHttpStatus($knownHttpStatus)->value;
        }

        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus ?? HttpStatus::BAD_REQUEST;

        parent::__construct($message ?? ErrorCode::BUSINESS_ERROR->getMessage(), $errorCode, $previous);
    }

    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): HttpStatus
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }
}
