<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\EndpointInvalid\DTO;

use Hyperf\Apex\DTO\Attribute\FieldDescription;

/**
 * 声明了「数组但没有元素类型」的 DTO。
 *
 * 这是规格校验归到 warning 的那类问题：`type: array` 却没有 `items`，
 * 消费方无从知道元素是什么 —— 生成命令把它当失败处理，不产出文档。
 */
final readonly class InvalidItemDTO
{
    public function __construct(
        #[FieldDescription('标签')]
        public array $tags = [],
    ) {
    }
}
