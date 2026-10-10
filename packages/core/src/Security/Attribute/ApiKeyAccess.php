<?php

declare(strict_types=1);

namespace Hyperf\Apex\Security\Attribute;

use Attribute;

/**
 * 以用户身份接入的客户端凭证接口，由 ApiKeyMiddleware 校验 `X-Api-Key`。
 *
 * 与 `#[IntegrationAccess]` 的区别是身份：集成凭证标识的是接入方（设备/服务端脚本），
 * 校验通过不写 Principal；本声明要求由用户密钥解析出真实用户身份，因此可以只靠身份访问，
 * 也可以叠加 `#[RequirePermission]` 做功能权限校验。
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class ApiKeyAccess
{
}
