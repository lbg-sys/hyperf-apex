<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Enum;

use Hyperf\Apex\Enum\Interface\EnumInterface;
use Hyperf\Apex\Enum\Traits\EnumTrait;
use Hyperf\Constants\Annotation\Constants;
use Hyperf\Constants\Annotation\Message;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionEnum;

/**
 * 枚举规范的契约测试。
 *
 * AGENTS 要求每个枚举都是 backed enum、实现 {@see EnumInterface}（并由 {@see EnumTrait} 提供
 * `message()` 等）、带 `#[Constants]`、且每个 case 都带**中文** `#[Message]` —— 文档里逐 case 的
 * 说明、接口层的 `getMessage()`、常量导出三件事都建立在这些前提上。
 *
 * 按目录扫描而不是硬编码清单：新增枚举同样被覆盖；扫描数量有下限断言，映射改了会立刻失败而不是
 * 悄悄空转。
 */
final class EnumConformanceTest extends TestCase
{
    /**
     * 包目录 → 命名空间前缀。
     *
     * 同一个包可能对应多个前缀（`reliability` 一个包内含 5 个命名空间），匹配时取最长的那个。
     *
     * @var array<string, string>
     */
    private const NAMESPACE_PREFIXES = [
        'packages/core/src/' => 'Hyperf\Apex\\',
        'packages/apidoc/src/' => 'Hyperf\Apex\ApiDoc\\',
        'packages/apidoc-apifox/src/' => 'Hyperf\Apex\ApiDoc\Apifox\\',
        'packages/websocket/src/' => 'Hyperf\Apex\WebSocket\\',
        'packages/metrics/src/' => 'Hyperf\Apex\Metrics\\',
        'packages/reliability/src/RateLimit/' => 'Hyperf\Apex\RateLimit\\',
        'packages/reliability/src/Idempotency/' => 'Hyperf\Apex\Idempotency\\',
        'packages/reliability/src/Outbox/' => 'Hyperf\Apex\Outbox\\',
        'packages/reliability/src/Notify/' => 'Hyperf\Apex\Notify\\',
        'packages/reliability/src/Outbound/' => 'Hyperf\Apex\Outbound\\',
        'packages/reliability/src/' => 'Hyperf\Apex\Reliability\\',
    ];

    public function testEveryEnumFollowsTheProjectConvention(): void
    {
        $enums = self::enums();
        $this->assertGreaterThan(10, count($enums), '没有扫到枚举：包目录或命名空间映射变了，这条断言会变成空转。');

        foreach ($enums as $class) {
            $reflection = new ReflectionEnum($class);

            $this->assertTrue($reflection->isBacked(), sprintf('%s 必须是 backed enum。', $class));
            $this->assertTrue(
                $reflection->implementsInterface(EnumInterface::class),
                sprintf('%s 必须实现 %s。', $class, EnumInterface::class),
            );
            $this->assertContains(
                EnumTrait::class,
                class_uses($class) ?: [],
                sprintf('%s 必须 use %s（message() / toArray() 等由它提供）。', $class, EnumTrait::class),
            );
            $this->assertNotSame(
                [],
                $reflection->getAttributes(Constants::class),
                sprintf('%s 必须标注 #[Constants]。', $class),
            );

            foreach ($reflection->getCases() as $case) {
                $attributes = $case->getAttributes(Message::class);
                $this->assertNotSame(
                    [],
                    $attributes,
                    sprintf('%s::%s 缺少 #[Message]：接口层的 message() 会因此抛错。', $class, $case->getName()),
                );

                $message = (string) $attributes[0]->newInstance()->value;
                $this->assertMatchesRegularExpression(
                    '/[\x{4e00}-\x{9fff}]/u',
                    $message,
                    sprintf('%s::%s 的 #[Message] 必须是中文（当前是「%s」）。', $class, $case->getName(), $message),
                );
            }
        }
    }

    /**
     * 全部枚举类名（由源码路径 + 命名空间映射推导，再交给自动加载确认）。
     *
     * @return list<class-string>
     */
    private static function enums(): array
    {
        $prefixes = self::NAMESPACE_PREFIXES;
        uksort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $root = dirname(__DIR__, 2);
        $enums = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/packages'));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (! str_contains($path, '/src/')) {
                continue;
            }

            foreach ($prefixes as $directory => $namespace) {
                if (! str_starts_with($path, $root . '/' . $directory)) {
                    continue;
                }

                $class = $namespace . str_replace('/', '\\', substr($path, strlen($root . '/' . $directory), -4));
                if (enum_exists($class)) {
                    $enums[] = $class;
                }

                break;
            }
        }

        sort($enums);

        return $enums;
    }
}
