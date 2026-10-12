<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\EndpointDangling;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\DTO\Attribute\Discriminator;
use Hyperf\Apex\DTO\Attribute\OneOf;

/**
 * 判别器映射里写了 GhostChildDTO，而 #[OneOf] 里只有 RegisteredChildDTO。
 *
 * 前者会出现在 discriminator.mapping 的引用里、却不会被注册成组件，产物中留下悬空 `$ref` ——
 * 这是 #[Discriminator] 最典型的误用（它的类注释也点明了这条约束），用它验证构建期断言真的生效。
 */
final readonly class DanglingRefDTO extends AbstractDTO
{
    public function __construct(
        #[OneOf(RegisteredChildDTO::class)]
        #[Discriminator('kind', [
            'registered' => RegisteredChildDTO::class,
            'ghost' => GhostChildDTO::class,
        ])]
        public array $payload = [],
    ) {
    }
}
