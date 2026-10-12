<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Security;

use Hyperf\Apex\Security\AccessDeclarationReader;
use Hyperf\Apex\Security\Listener\PermissionDeclarationCheckListener;
use Hyperf\Config\Config;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * 启动期访问声明检查的编排行为。
 *
 * 校验规则本身（口径缺失、多个口径冲突）建立在「注解收集器已经扫过应用」这个前提上，
 * 单元测试环境里收集器是空的 —— 所以这里只钉住两件不依赖扫描结果的事：
 * 没有控制器时不误报、关掉开关时整体跳过。扫描行为需要在应用启动时验证。
 */
final class PermissionDeclarationCheckListenerTest extends TestCase
{
    public function testItPassesWhenNoControllerHasBeenCollected(): void
    {
        $listener = new PermissionDeclarationCheckListener(new Config([]), new AccessDeclarationReader());

        $listener->process(new stdClass());

        $this->addToAssertionCount(1);
    }

    public function testItIsSkippedWhenDisabled(): void
    {
        $listener = new PermissionDeclarationCheckListener(new Config([
            'security' => ['declaration_check' => ['enabled' => false]],
        ]), new AccessDeclarationReader());

        $listener->process(new stdClass());

        $this->addToAssertionCount(1);
    }
}
