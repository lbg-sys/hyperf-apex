<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\DTO;

use Hyperf\Apex\DTO\Attribute\MapOf;
use Hyperf\Apex\DTO\Contract\DocumentationOnlyAttribute;
use Hyperf\Apex\DTO\Contract\ItemRuleAttribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Contract\ValidationRuleAttribute;
use PHPUnit\Framework\TestCase;

/**
 * 约束 Attribute 必须在「参与校验」与「不参与校验」之间表态。
 *
 * 只实现 {@see SchemaAttribute} 又不表态的属性，等于「看起来会校验、实际不校验」：使用方按文档
 * 以为服务端会拒绝，实际照单全收。这条断言让新增 Attribute 时无法回避这个选择 ——
 * 目录扫描而不是硬编码清单，就是为了让新增的文件也被覆盖到。
 */
final class SchemaAttributeContractTest extends TestCase
{
    /**
     * 通过元素类型间接参与校验的属性：它自己不产出校验规则，但值层确实会被校验
     * （`DTOMetadataFactory` 从它取元素类型表达式），所以不能标成「不参与校验」。
     *
     * @var list<class-string>
     */
    private const INDIRECTLY_VALIDATED = [
        MapOf::class,
    ];

    public function testEverySchemaAttributeDeclaresWhetherItValidates(): void
    {
        $classes = self::attributeClasses();
        $this->assertGreaterThan(30, count($classes), '没有扫到 Attribute 类：目录路径或自动加载变了，这条断言会变成空转。');

        $unclassified = [];
        foreach ($classes as $class) {
            if (! is_a($class, SchemaAttribute::class, true) || in_array($class, self::INDIRECTLY_VALIDATED, true)) {
                continue;
            }

            $validates = is_a($class, ValidationRuleAttribute::class, true)
                || is_a($class, ItemRuleAttribute::class, true);
            if ($validates || is_a($class, DocumentationOnlyAttribute::class, true)) {
                continue;
            }

            $unclassified[] = $class;
        }

        $this->assertSame([], $unclassified, sprintf(
            "以下 Attribute 既没实现校验契约、也没标 DocumentationOnlyAttribute：\n - %s\n"
            . '要么让它参与校验（实现 ValidationRuleAttribute / ItemRuleAttribute），'
            . '要么标记为「不参与请求校验」。',
            implode("\n - ", $unclassified),
        ));
    }

    public function testDocumentationOnlyAttributesDoNotProduceValidationRules(): void
    {
        // 标了「不参与校验」却同时实现校验契约，说明标记用错了地方 —— 两个方向都会误导使用方。
        foreach (self::attributeClasses() as $class) {
            if (! is_a($class, DocumentationOnlyAttribute::class, true)) {
                continue;
            }

            $this->assertFalse(
                is_a($class, ValidationRuleAttribute::class, true) || is_a($class, ItemRuleAttribute::class, true),
                sprintf('%s 同时标记了 DocumentationOnlyAttribute 与校验规则契约。', $class),
            );
        }
    }

    /**
     * `src/DTO/Attribute/` 下的全部 Attribute 类。
     *
     * @return list<class-string>
     */
    private static function attributeClasses(): array
    {
        $classes = [];
        foreach (glob(dirname(__DIR__, 2) . '/packages/core/src/DTO/Attribute/*.php') ?: [] as $file) {
            $class = 'Hyperf\Apex\DTO\Attribute\\' . basename($file, '.php');
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
