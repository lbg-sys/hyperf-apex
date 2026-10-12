<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\EndpointDangling;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\DTO\Attribute\FieldDescription;

/** 出现在 #[OneOf] 里：会被注册成组件。 */
final readonly class RegisteredChildDTO extends AbstractDTO
{
    public function __construct(
        #[FieldDescription('名称')]
        public string $name = '',
    ) {
    }
}
