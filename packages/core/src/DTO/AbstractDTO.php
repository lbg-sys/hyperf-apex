<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO;

use Hyperf\Contract\Arrayable;
use Hyperf\Contract\Jsonable;
use JsonSerializable;

/**
 * 强类型数据传输对象基类。
 *
 * @implements Arrayable<string, mixed>
 */
abstract readonly class AbstractDTO implements Arrayable, Jsonable, JsonSerializable
{
    use DTOJsonableTrait;
}
