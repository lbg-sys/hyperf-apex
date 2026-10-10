<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security\Attribute;

use Attribute;

/**
 * 需要登录，但不需要功能权限。
 *
 * 适用于只操作调用者自身数据的接口。
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Authenticated
{
}
