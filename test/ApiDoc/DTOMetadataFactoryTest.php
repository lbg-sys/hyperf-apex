<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\DTO\Attribute\ArrayOf;
use Hyperf\Apex\DTO\Attribute\ItemMinimum;
use Hyperf\Apex\DTO\Attribute\Length;
use Hyperf\Apex\DTO\Attribute\Minimum;
use Hyperf\Apex\DTO\Attribute\NotNull;
use Hyperf\Apex\DTO\Attribute\Optional;
use Hyperf\Apex\DTO\Attribute\Required;
use Hyperf\Apex\DTO\Metadata\DTOClassMetadata;
use Hyperf\Apex\DTO\Metadata\DTOMetadataFactory;
use PHPUnit\Framework\TestCase;
use ReflectionParameter;

/**
 * DTO 元数据的契约：必填/nullable 的推断优先级、#[ArrayOf] 解析成类型表达式、两类 Attribute 各归其位。
 *
 * 这些推断是校验规则、文档与水合三处共同的输入，错一处就是三处一起错 —— 因此边界都要有断言：
 * `#[Required]` 能否压过默认值、`#[NotNull]` 能否压过可空类型、ItemRuleAttribute 不该混进校验规则。
 */
final class DTOMetadataFactoryTest extends TestCase
{
    public function testMetadataIsCachedPerClass(): void
    {
        // 反射是构建期与请求期共用的输入，每个请求重算一遍没有意义。
        $this->assertSame(
            DTOMetadataFactory::from(MetadataFixtureDTO::class),
            DTOMetadataFactory::from(MetadataFixtureDTO::class),
        );
    }

    public function testParametersKeepDeclarationOrder(): void
    {
        $names = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $this->metadata()->parameters,
        );

        $this->assertSame(
            ['noDefault', 'forced', 'optional', 'plain', 'nullable', 'notNull', 'nested', 'leaf', 'bare', 'checked', 'items'],
            $names,
        );
    }

    public function testRequiredFollowsDefaultOptionalAndExplicitRequired(): void
    {
        $properties = $this->metadata()->properties;

        // #[Required] 压过「有默认值」这条推断。
        $this->assertTrue($properties['forced']->required);
        // 没有默认值且没标 #[Optional]。
        $this->assertTrue($properties['noDefault']->required);
        // 标了 #[Optional] 就是可选，即使没有默认值。
        $this->assertFalse($properties['optional']->required);
        // 有默认值即不必填。
        $this->assertFalse($properties['plain']->required);
    }

    public function testNullableFollowsTypeUnlessNotNullIsDeclared(): void
    {
        $properties = $this->metadata()->properties;

        $this->assertTrue($properties['nullable']->nullable);
        // `?string` 但显式声明不允许 null。
        $this->assertFalse($properties['notNull']->nullable);
        $this->assertFalse($properties['plain']->nullable);
    }

    public function testArrayOfBecomesItemTypeExpression(): void
    {
        $properties = $this->metadata()->properties;

        $nested = $properties['nested']->itemType;
        $this->assertNotNull($nested);
        $this->assertFalse($nested->isLeaf(), 'list<int> 不是叶子表达式。');

        $leaf = $properties['leaf']->itemType;
        $this->assertNotNull($leaf);
        $this->assertTrue($leaf->isLeaf());
        $this->assertSame('string', $leaf->name);

        $this->assertNull($properties['bare']->itemType, '没有 #[ArrayOf] 时不该凭空产生元素类型。');
    }

    public function testValidationAndItemAttributesAreCollectedSeparately(): void
    {
        $properties = $this->metadata()->properties;

        $this->assertCount(2, $properties['checked']->validationAttributes);
        $this->assertCount(0, $properties['checked']->itemAttributes);

        // ItemMinimum 是作用在数组元素上的规则，不能混进字段自身的校验规则。
        $this->assertCount(1, $properties['items']->itemAttributes);
        $this->assertCount(0, $properties['items']->validationAttributes);
    }

    private function metadata(): DTOClassMetadata
    {
        return DTOMetadataFactory::from(MetadataFixtureDTO::class);
    }
}

final readonly class MetadataFixtureDTO
{
    public function __construct(
        // 必填参数必须写在最前：PHP 不允许「有默认值」的参数出现在「没有默认值」的参数之前。
        public string $noDefault,
        #[Required]
        public string $forced = '',
        #[Optional]
        public string $optional = '',
        public string $plain = '',
        public ?string $nullable = null,
        #[NotNull]
        public ?string $notNull = null,
        #[ArrayOf('list<int>')]
        public array $nested = [],
        #[ArrayOf('string')]
        public array $leaf = [],
        public array $bare = [],
        #[Length(min: 1)]
        #[Minimum(1)]
        public string $checked = '',
        #[ItemMinimum(1)]
        public array $items = [],
    ) {
    }
}
