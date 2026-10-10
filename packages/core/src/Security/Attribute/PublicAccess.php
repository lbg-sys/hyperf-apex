<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security\Attribute;

use Attribute;

/**
 * 公开接口，不需要认证也不需要功能权限。
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class PublicAccess
{
}
