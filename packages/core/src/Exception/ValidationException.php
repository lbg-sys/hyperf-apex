<?php

declare(strict_types=1);

namespace Hyperf\Apex\Exception;

use Hyperf\Apex\Enum\ErrorCode;
use Throwable;

class ValidationException extends BusinessException
{
    /**
     * @param array<string, mixed> $errors
     */
    public function __construct(
        private readonly array $errors = [],
        ?string $message = null,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            ErrorCode::VALIDATION_FAILED,
            $message,
            previous: $previous,
            data: ['errors' => $errors]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
