<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\EndpointCollision\Controller;

use Hyperf\Apex\ApiDoc\Attribute\ApiFolder;
use Hyperf\Apex\ApiDoc\Attribute\ApiOperation;
use Hyperf\Apex\Security\Attribute\PublicAccess;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;

/**
 * 与 `.../Endpoint/Controller/FixtureController::getList` 撞 operationId 的控制器。
 *
 * `/fixture/get_list` 与 `/fixture/get` + `/list` 是两条不同的 URL，但 operationId 由
 * 「方法 + URL path」清洗而来，两者都落成 `get_fixture_get_list`。单独放一个目录，
 * 免得这个「有意制造的冲突」影响其它用例的扫描结果。
 */
#[ApiFolder('示例/冲突')]
#[Controller(prefix: '/fixture/get')]
final class CollisionController
{
    #[PublicAccess]
    #[ApiOperation(summary: '与 get_list 撞 operationId', tags: ['示例'])]
    #[GetMapping(path: 'list')]
    public function list(): void
    {
    }
}
