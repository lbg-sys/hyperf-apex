<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\Endpoint\Controller;

use Hyperf\Apex\ApiDoc\Attribute\ApiFolder;
use Hyperf\Apex\ApiDoc\Attribute\ApiOperation;
use Hyperf\Apex\Http\ArgumentResolver\Attribute\FromBody;
use Hyperf\Apex\Http\ArgumentResolver\Attribute\FromQuery;
use Hyperf\Apex\Security\Attribute\Authenticated;
use Hyperf\Apex\Security\Attribute\PublicAccess;
use Hyperf\Apex\Test\ApiDoc\Endpoint\DTO\FixtureCreateDTO;
use Hyperf\Apex\Test\ApiDoc\Endpoint\DTO\FixtureItemDTO;
use Hyperf\Apex\Test\ApiDoc\Endpoint\DTO\FixtureQueryDTO;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * OpenAPI 端到端生成的被扫描对象。
 *
 * 放在 `.../Controller/` 下是必须的：`ControllerScanner::candidateClasses()` 只收路径里含
 * `/Controller/` 的文件。两个方法刻意覆盖两条典型形态 —— 带访问声明的 GET（query DTO + 响应 DTO）
 * 与 POST（body DTO + `void` 响应），后者同时也是「无响应体」那条契约的现场。
 */
#[ApiFolder('示例/条目')]
#[Controller(prefix: '/fixture')]
final class FixtureController
{
    #[PublicAccess]
    #[ApiOperation(summary: '查询条目列表', tags: ['示例'])]
    #[GetMapping(path: 'get_list')]
    public function getList(#[FromQuery] FixtureQueryDTO $query): FixtureItemDTO
    {
        return new FixtureItemDTO();
    }

    #[Authenticated]
    #[ApiOperation(summary: '创建条目', tags: ['示例'])]
    #[PostMapping(path: 'create')]
    public function create(#[FromBody] FixtureCreateDTO $body): void
    {
    }
}
