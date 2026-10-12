<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\EndpointDangling;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\DTO\Attribute\FieldDescription;

/** 只出现在判别器 mapping 里、没有进 #[OneOf]：不会被注册成组件。 */
final readonly class GhostChildDTO extends AbstractDTO
{
    public function __construct(
        #[FieldDescription('名称')]
        public string $name = '',
    ) {
    }
}
