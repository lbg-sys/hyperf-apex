<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\Response\Attribute;

use Attribute;
use Hyperf\Apex\Http\Response\Contract\ResponseEncoderInterface;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class ResponseEncoder
{
    /** @param class-string<ResponseEncoderInterface> $type */
    public function __construct(public readonly string $type)
    {
        if (! is_subclass_of($type, ResponseEncoderInterface::class)) {
            throw new InvalidArgumentException(sprintf('Response encoder must implement %s, %s given.', ResponseEncoderInterface::class, $type));
        }
    }
}
