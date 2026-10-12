<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\EndpointDangling;

use Hyperf\Apex\ApiDoc\Attribute\ApiOperation;
use Hyperf\Apex\Security\Attribute\PublicAccess;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;

/** 只用于验证悬空组件引用会被拦住：接口本身是合法的。 */
#[Controller(prefix: '/dangling')]
final class DanglingController
{
    #[ApiOperation(summary: '判别器漏写 OneOf 的样例')]
    #[PublicAccess]
    #[GetMapping(path: 'get')]
    public function show(): DanglingRefDTO
    {
        return new DanglingRefDTO();
    }
}
