<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\Endpoint\Controller;

use Hyperf\Apex\ApiDoc\Attribute\ApiError;
use Hyperf\Apex\ApiDoc\Attribute\ApiFolder;
use Hyperf\Apex\ApiDoc\Attribute\ApiOperation;
use Hyperf\Apex\ApiDoc\Attribute\FileField;
use Hyperf\Apex\DTO\Attribute\ArrayOf;
use Hyperf\Apex\DTO\PaginationResultDTO;
use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Http\ArgumentResolver\Attribute\FromMultipart;
use Hyperf\Apex\Http\ArgumentResolver\Attribute\FromQuery;
use Hyperf\Apex\RateLimit\Attribute\RateLimit;
use Hyperf\Apex\Security\Attribute\Authenticated;
use Hyperf\Apex\Security\Attribute\PublicAccess;
use Hyperf\Apex\Test\ApiDoc\Endpoint\DTO\FixtureItemDTO;
use Hyperf\Apex\Test\ApiDoc\Endpoint\DTO\FixtureQueryDTO;
use Hyperf\Apex\Test\ApiDoc\Endpoint\DTO\FixtureUploadDTO;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;

/**
 * 进阶形态的夹具：`#[ApiError]`、泛型分页返回、`#[FileField]` 上传、`#[RateLimit]`。
 *
 * 与 `FixtureController` 放在同一目录，共用一套扫描与装配；断言分别落在生成文档的不同位置。
 */
#[ApiFolder('示例/进阶')]
#[Controller(prefix: '/advanced')]
final class AdvancedFixtureController
{
    #[PublicAccess]
    #[ApiOperation(summary: '查询条目详情', tags: ['示例'])]
    #[ApiError(ErrorCode::RESOURCE_NOT_FOUND, ErrorCode::RESOURCE_CONFLICT)]
    #[RateLimit(name: 'advanced_detail', capacity: 10, refill: 1)]
    #[GetMapping(path: 'get_detail')]
    public function getDetail(#[FromQuery] FixtureQueryDTO $query): FixtureItemDTO
    {
        return new FixtureItemDTO();
    }

    #[PublicAccess]
    #[ApiOperation(summary: '分页查询条目', tags: ['示例'])]
    #[ArrayOf(FixtureItemDTO::class, property: 'list')]
    #[GetMapping(path: 'get_page')]
    public function getPage(#[FromQuery] FixtureQueryDTO $query): PaginationResultDTO
    {
        return new PaginationResultDTO([], 0);
    }

    #[Authenticated]
    #[ApiOperation(summary: '上传附件', tags: ['示例'])]
    #[FileField('file', '待上传的文件', mediaType: 'image/*')]
    #[PostMapping(path: 'upload')]
    public function upload(#[FromMultipart] FixtureUploadDTO $form): void
    {
    }
}
