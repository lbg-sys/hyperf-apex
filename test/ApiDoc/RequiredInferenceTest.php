<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\ApiDoc\DTOSchemaFactory;
use Hyperf\Apex\DTO\Attribute\Optional;
use Hyperf\Apex\DTO\Attribute\Required;
use PHPUnit\Framework\TestCase;

/**
 * 文档侧的 required 列表。
 *
 * 判据只有一处实现（`DTOMetadataFactory`），文档侧不再自己算一遍：两处各写一遍时，
 * 「`#[Required]` / 构造器默认值 / `#[Optional]`」三者的优先级只要有一侧改动，同一个 DTO
 * 就会在文档与校验上得到不同的 required 列表 —— 而这两处恰好是客户端与服务端各自的依据。
 */
final class RequiredInferenceTest extends TestCase
{
    public function testRequiredListComesFromTheSharedInference(): void
    {
        $fragment = (new DTOSchemaFactory())->objectFragment(RequiredRulesDTO::class);

        $this->assertSame(['mustFill', 'requiredByAttribute'], $fragment->get('required'));
    }
}

/** 必填的四种来源：无默认值、`#[Required]`，以及被默认值 / `#[Optional]` 豁免的两种。 */
final readonly class RequiredRulesDTO
{
    public function __construct(
        public string $mustFill,
        #[Required]
        public string $requiredByAttribute = '',
        public string $hasDefault = '',
        #[Optional]
        public string $optionalWithoutDefault = '',
    ) {
    }
}
