<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\Response\Contract;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Contract\Arrayable;

/** @extends Arrayable<string, mixed> */
interface ApiResponseInterface extends Arrayable
{
    public static function success(mixed $data = [], string $message = '成功'): self;

    public static function make(ErrorCode|int $code, ?string $message = null, mixed $data = []): self;
}
