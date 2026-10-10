<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Contract;

use Hyperf\Apex\DTO\Schema\SchemaFragment;

interface ItemSchemaAttribute
{
    public function itemSchemaFragment(): SchemaFragment;
}
