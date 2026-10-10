<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\EndpointVersion30\Controller;

use Hyperf\Apex\ApiDoc\Attribute\ApiFolder;
use Hyperf\Apex\ApiDoc\Attribute\ApiOperation;
use Hyperf\Apex\Security\Attribute\PublicAccess;
use Hyperf\Apex\Test\ApiDoc\EndpointVersion30\DTO\Version30ItemDTO;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;

/**
 * 声明里用了 3.1 专有关键字（`#[PatternProperties]`）的控制器。
 *
 * 单独一个目录：它只服务于「目标版本 3.0.x 时生成必须失败」这一条用例，
 * 混进主夹具会让别的用例整体失败。
 */
#[ApiFolder('示例/三零')]
#[Controller(prefix: '/v30')]
final class Version30FixtureController
{
    #[PublicAccess]
    #[ApiOperation(summary: '三零版本示例', tags: ['示例'])]
    #[GetMapping(path: 'get_item')]
    public function getItem(): Version30ItemDTO
    {
        return new Version30ItemDTO();
    }
}
