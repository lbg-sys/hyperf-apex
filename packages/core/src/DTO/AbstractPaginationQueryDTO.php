<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO;

use Hyperf\Apex\DTO\Attribute\FieldDescription;
use Hyperf\Apex\DTO\Attribute\Maximum;
use Hyperf\Apex\DTO\Attribute\Minimum;

abstract readonly class AbstractPaginationQueryDTO extends AbstractDTO
{
    #[FieldDescription('页码，从 1 开始；不传时由服务端取默认页'), Minimum(1)]
    public ?int $page;

    #[FieldDescription('每页条数，上限 100；不传时由服务端取默认值'), Minimum(1), Maximum(100)]
    public ?int $limit;

    public function __construct(?int $page = null, ?int $limit = null)
    {
        $this->page = $page;
        $this->limit = $limit;
    }
}
