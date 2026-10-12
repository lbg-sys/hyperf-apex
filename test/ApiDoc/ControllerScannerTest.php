<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use FilesystemIterator;
use Hyperf\Apex\ApiDoc\Builder\ControllerScanner;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;

/**
 * 控制器发现规则。
 *
 * 两条曾经的规则都会出错，而且都是「静默或灭顶」两种极端：
 * - 只接受路径含 `/Controller/` 的文件 → 控制器放在别的目录下会被漏收，文档整体为空也没有迹象；
 * - 用正则从文件内容里抽类名 → `app/Controller/` 下放一个 trait / interface 就直接抛错，
 *   整个生成命令失败。现在扫描全部 `.php` 并用 token 判定类声明。
 */
final class ControllerScannerTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/apex-scanner-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/Controller/Traits', 0777, true);
        mkdir($this->directory . '/Api', 0777, true);
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->directory);
    }

    public function testNonClassFilesAreSkippedInsteadOfFailingTheCommand(): void
    {
        file_put_contents($this->directory . '/Controller/Traits/Helper.php', <<<'PHP'
<?php

namespace ApexScannerFixture\Controller\Traits;

trait Helper
{
}
PHP);

        $this->assertSame([], (new ControllerScanner([]))->controllers($this->directory));
    }

    public function testControllerOutsideTheControllerDirectoryIsStillFound(): void
    {
        $file = $this->directory . '/Api/ThingController.php';
        file_put_contents($file, <<<'PHP'
<?php

namespace ApexScannerFixture\Api;

use Hyperf\HttpServer\Annotation\Controller;

#[Controller(prefix: '/api_thing')]
class ThingController
{
}
PHP);

        require_once $file;

        $controllers = (new ControllerScanner([]))->controllers($this->directory);

        $this->assertSame(
            ['ApexScannerFixture\Api\ThingController'],
            array_map(static fn (ReflectionClass $controller): string => $controller->getName(), $controllers),
        );
    }

    public function testAnonymousClassAndClassConstantAreNotMistakenForDeclarations(): void
    {
        $file = $this->directory . '/Controller/Factory.php';
        file_put_contents($file, <<<'PHP'
<?php

namespace ApexScannerFixture\Controller;

class Factory
{
    public function make(): object
    {
        $name = self::class;

        return new class() {
            public string $name = '';
        };
    }
}
PHP);

        require_once $file;

        $controllers = (new ControllerScanner([]))->controllers($this->directory);

        // 文件里确实有一个具名类，但它没有 #[Controller]，所以不进文档；
        // 关键是「匿名类没有把扫描搞乱」，也不该抛异常。
        $this->assertSame([], $controllers);
    }

    public function testMissingAppPathReportsTheConfigKey(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('api_doc.app_path');

        (new ControllerScanner([]))->controllers($this->directory . '/not-exists');
    }
}
