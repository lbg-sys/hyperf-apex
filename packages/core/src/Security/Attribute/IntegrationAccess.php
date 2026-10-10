<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security\Attribute;

use Attribute;

/**
 * 设备或客户端凭证接口，由各自的集成中间件校验，不进用户权限体系。
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class IntegrationAccess
{
}
