<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\EndpointVersion30Compatible\Controller;

use Hyperf\Apex\ApiDoc\Attribute\ApiFolder;
use Hyperf\Apex\ApiDoc\Attribute\ApiOperation;
use Hyperf\Apex\Security\Attribute\PublicAccess;
use Hyperf\Apex\Test\ApiDoc\EndpointVersion30Compatible\DTO\SafeItemDTO;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;

/**
 * 只声明 3.0 能表达的东西：目标版本设成 3.0.x 时它必须照常生成。
 */
#[ApiFolder('示例/三零兼容')]
#[Controller(prefix: '/v30safe')]
final class SafeFixtureController
{
    #[PublicAccess]
    #[ApiOperation(summary: '三零兼容示例', tags: ['示例'])]
    #[GetMapping(path: 'get_item')]
    public function getItem(): SafeItemDTO
    {
        return new SafeItemDTO();
    }
}
