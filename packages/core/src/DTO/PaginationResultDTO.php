<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO;

use Hyperf\Apex\DTO\Attribute\FieldDescription;

/** @template-covariant TItem */
final readonly class PaginationResultDTO extends AbstractDTO
{
    /** @var list<TItem> */
    #[FieldDescription('数据列表')]
    public array $list;

    #[FieldDescription('数据总数')]
    public int $total;

    /** @param list<TItem> $list */
    public function __construct(array $list, int $total)
    {
        $this->list = $list;
        $this->total = $total;
    }
}
