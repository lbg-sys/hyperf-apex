<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\Response;

use Psr\Http\Message\ServerRequestInterface;
use ReflectionMethod;

final readonly class ResponseTransformationContext
{
    public function __construct(
        public mixed $value,
        public ?ReflectionMethod $method,
        public ServerRequestInterface $request,
    ) {
    }
}
