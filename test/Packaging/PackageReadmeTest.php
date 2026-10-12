<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Packaging;

use PHPUnit\Framework\TestCase;

/**
 * 每个包目录都必须带 README.md。
 *
 * 拆包发布时（`.github/workflows/split.yml` 用 `git subtree split --prefix=packages/<包>`）包目录
 * 就是只读仓的根，而 Packagist 的包页面渲染的正是那个仓的 README —— 缺了它，用户在 Packagist 与
 * 只读仓里都看不到任何用法说明，只能回去翻源码。
 *
 * 目录扫描而不是硬编码清单：以后新增包同样被覆盖；扫描数量带下限断言，路径变了会立刻失败而不是空转。
 */
final class PackageReadmeTest extends TestCase
{
    public function testEveryPackageShipsAReadme(): void
    {
        $packages = glob(dirname(__DIR__, 2) . '/packages/*', GLOB_ONLYDIR) ?: [];
        $this->assertGreaterThan(5, count($packages), '没扫到包目录：路径变了，这条断言会变成空转。');

        foreach ($packages as $directory) {
            $name = basename($directory);
            $readmePath = $directory . '/README.md';

            $this->assertFileExists($readmePath, sprintf('%s 缺 README.md（拆包后它就是只读仓的根文档）。', $name));

            $readme = (string) file_get_contents($readmePath);

            $this->assertStringContainsString(
                'composer require hyperf-apex/' . $name,
                $readme,
                sprintf('%s 的 README 没写安装命令，读者得自己猜包名。', $name),
            );

            $this->assertStringContainsString(
                'CHANGELOG.md',
                $readme,
                sprintf('%s 的 README 没有指向变更记录：破坏性变更都记在主仓库的 CHANGELOG 里。', $name),
            );

            $this->assertGreaterThan(
                1500,
                strlen($readme),
                sprintf('%s 的 README 太短（%d 字节），大概率只是个占位。', $name, strlen($readme)),
            );
        }
    }
}
