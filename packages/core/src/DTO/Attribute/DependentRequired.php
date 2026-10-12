<?php

declare(strict_types=1);

namespace Hyperf\Apex\DTO\Attribute;

use Attribute;
use Hyperf\Apex\DTO\Contract\DocumentationOnlyAttribute;
use Hyperf\Apex\DTO\Contract\SchemaAttribute;
use Hyperf\Apex\DTO\Schema\SchemaFragment;
use InvalidArgumentException;

/**
 * 条件必填依赖（JSON Schema dependentRequired）：某个字段出现时，连带要求另外一批字段必填。
 * 例如传输方式选「自定义」时必须同时提供主机与端口：['custom' => ['host', 'port']]。
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class DependentRequired implements SchemaAttribute, DocumentationOnlyAttribute
{
    /**
     * @param array<string, list<string>> $map 触发字段名 => 连带必填的字段名列表
     */
    public function __construct(public readonly array $map)
    {
    }

    public function fragment(): SchemaFragment
    {
        foreach ($this->map as $property => $required) {
            if ($property === '') {
                throw new InvalidArgumentException('dependentRequired 的键必须是非空的字段名。');
            }

            if ($required === []) {
                throw new InvalidArgumentException(sprintf('dependentRequired[%s] 必须是非空的字段名数组。', $property));
            }

            foreach ($required as $name) {
                if ($name === '') {
                    throw new InvalidArgumentException(sprintf('dependentRequired[%s] 的元素必须是非空字符串。', $property));
                }
            }
        }

        return SchemaFragment::of(['type' => 'object', 'dependentRequired' => $this->map]);
    }
}
