<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\DocumentationOnlyAttribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\ComponentName;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use InvalidArgumentException;

/**
 * 多态判别器：告诉消费方用哪个字段的值来决定实际使用 oneOf 里的哪个子 Schema。
 *
 * 需要与 #[OneOf] 搭配使用，mapping 把字段取值映射到 DTO 类名 —— 与其余组合类
 * Attribute 一样写类名而不是组件名字符串，拼错会在构建期报错而不是留下悬空 $ref。
 * mapping 里的类必须同时出现在 #[OneOf] 里，否则它不会被注册成组件。
 *
 * 例：#[OneOf(TextContentDTO::class, FileContentDTO::class)]
 *     #[Discriminator('contentType', ['text' => TextContentDTO::class, 'file' => FileContentDTO::class])]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Discriminator implements SchemaAttribute, DocumentationOnlyAttribute
{
    /** @param array<string, string> $mapping 字段取值 => DTO 类名 */
    public function __construct(
        public readonly string $propertyName,
        public readonly array $mapping = [],
    ) {
    }

    public function fragment(): SchemaFragment
    {
        $mapping = [];
        foreach ($this->mapping as $value => $class) {
            if (! class_exists($class)) {
                throw new InvalidArgumentException(sprintf(
                    'discriminator.mapping[%s] 的 %s 不是可解析的类名，请写 DTO::class。',
                    (string) $value,
                    $class
                ));
            }

            $mapping[(string) $value] = '#/components/schemas/' . ComponentName::of($class);
        }

        return SchemaFragment::of([
            'discriminator' => array_filter([
                'propertyName' => $this->propertyName,
                'mapping' => $mapping !== [] ? $mapping : null,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }
}
