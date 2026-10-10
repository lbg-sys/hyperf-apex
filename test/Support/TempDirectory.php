<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 用例自己创建与回收的临时目录。
 *
 * 命令类用例要真的往磁盘写产物，不能写进仓库（那会盖掉别人本地生成的文档），
 * 所以统一落在系统临时目录里并在 tearDown 里清掉。回收是递归的：有的用例会故意
 * 造多级目录来验证「目录不存在时命令自己建出来」。
 */
final class TempDirectory
{
    public static function create(string $prefix): string
    {
        return sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(4));
    }

    public static function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
