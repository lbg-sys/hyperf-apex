<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security\Contract;

use Hyperf\Apex\Security\Principal;

interface PrincipalContextInterface
{
    public function set(Principal $principal): void;

    public function get(): Principal;

    public function clear(): void;
}
