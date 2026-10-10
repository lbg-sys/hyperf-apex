<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\EndpointInvalid\Controller;

use Hyperf\Apex\ApiDoc\Attribute\ApiFolder;
use Hyperf\Apex\ApiDoc\Attribute\ApiOperation;
use Hyperf\Apex\Security\Attribute\PublicAccess;
use Hyperf\Apex\Test\ApiDoc\EndpointInvalid\DTO\InvalidItemDTO;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;

/**
 * 只服务于「生成期校验失败 → 命令退出非零且不写产物」这一条用例。
 */
#[ApiFolder('示例/非法')]
#[Controller(prefix: '/invalid')]
final class InvalidFixtureController
{
    #[PublicAccess]
    #[ApiOperation(summary: '非法声明示例', tags: ['示例'])]
    #[GetMapping(path: 'get_item')]
    public function getItem(): InvalidItemDTO
    {
        return new InvalidItemDTO();
    }
}
