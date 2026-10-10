<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\Response\Contract;

interface ResponseEncoderInterface
{
    public function contentType(): string;

    public function encode(ApiResponseInterface $response): string;
}
