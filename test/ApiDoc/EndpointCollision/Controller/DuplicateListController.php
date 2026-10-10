<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\EndpointCollision\Controller;

use Hyperf\Apex\ApiDoc\Attribute\ApiFolder;
use Hyperf\Apex\ApiDoc\Attribute\ApiOperation;
use Hyperf\Apex\Security\Attribute\PublicAccess;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;

/**
 * 与同目录的 {@see CollisionController} 撞 operationId 的另一半：
 * `/fixture/get_list` 与 `/fixture/get/list` 清洗后都是 `get_fixture_get_list`。
 *
 * 两条路由放在同一个目录里，这个夹具才是自包含的 —— 只扫描它就能复现冲突。
 */
#[ApiFolder('示例/冲突')]
#[Controller(prefix: '/fixture')]
final class DuplicateListController
{
    #[PublicAccess]
    #[ApiOperation(summary: '与 /fixture/get/list 撞 operationId', tags: ['示例'])]
    #[GetMapping(path: 'get_list')]
    public function getList(): void
    {
    }
}
