<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc;

use Hyperf\Apex\Test\ApiDoc\Support\OpenApiGeneratorHarness;
use JsonException;
use PHPUnit\Framework\TestCase;

/**
 * 整份文档的快照：逐字节比对 `test/ApiDoc/Snapshot/openapi.json`。
 *
 * 局部断言只能守住「写出来的那几条」，守不住「有没有多出/少掉什么」：某个关键字被顺手加回来、
 * 组件顺序因文件系统遍历顺序变化而改变，这些都只有全量比对才看得见。项目里「组件要排序」
 * 「快照逐字节比对」这些设计取舍，正是为了这条用例能成立。
 *
 * 预期变更后用 `UPDATE_API_DOC_SNAPSHOT=1 composer62 test` 重新生成，并人工审查 diff。
 */
final class OpenApiDocumentSnapshotTest extends TestCase
{
    /** 差异行数上限：再多也只是噪音，说明这次改动不是「微调」。 */
    private const MAX_REPORTED_DIFFERENCES = 20;

    public function testFixtureDocumentMatchesSnapshot(): void
    {
        $json = self::encode($this->document());
        $path = __DIR__ . '/Snapshot/openapi.json';

        if (getenv('UPDATE_API_DOC_SNAPSHOT') === '1') {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, $json);
            $this->markTestSkipped('快照已重新生成：' . $path);
        }

        if (! is_file($path)) {
            $this->fail('快照不存在，先用 UPDATE_API_DOC_SNAPSHOT=1 composer62 test 生成：' . $path);
        }

        $expected = (string) file_get_contents($path);
        if ($expected === $json) {
            $this->assertSame($expected, $json);

            return;
        }

        $this->fail(sprintf(
            "文档结构与快照不一致：\n%s\n确认是有意变更后，用 UPDATE_API_DOC_SNAPSHOT=1 composer62 test 更新快照。",
            self::describeDifference($expected, $json),
        ));
    }

    public function testGenerationIsDeterministic(): void
    {
        // 组件与路径都排过序，同一份源码重复生成必须逐字节一致 —— 否则快照会在别的机器上抖。
        $this->assertSame(self::encode($this->document()), self::encode($this->document()));
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        return OpenApiGeneratorHarness::generator()->generate(__DIR__ . '/Endpoint');
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function encode(array $document): string
    {
        try {
            $json = json_encode(
                $document,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            self::fail('文档无法序列化：' . $exception->getMessage());
        }

        return $json . PHP_EOL;
    }

    /**
     * 只报告有差异的行（最多 MAX_REPORTED_DIFFERENCES 处）。
     *
     * 全量 JSON diff 是几百行噪音，看不出到底改了什么。
     */
    private static function describeDifference(string $expected, string $actual): string
    {
        $expectedLines = explode("\n", $expected);
        $actualLines = explode("\n", $actual);
        $lines = [];
        $reported = 0;

        for ($index = 0; $index < max(count($expectedLines), count($actualLines)); ++$index) {
            $expectedLine = $expectedLines[$index] ?? null;
            $actualLine = $actualLines[$index] ?? null;
            if ($expectedLine === $actualLine) {
                continue;
            }

            if ($reported >= self::MAX_REPORTED_DIFFERENCES) {
                $lines[] = '…（其余差异已省略）';
                break;
            }

            $lines[] = sprintf('第 %d 行 - %s', $index + 1, trim((string) $expectedLine));
            $lines[] = sprintf('第 %d 行 + %s', $index + 1, trim((string) $actualLine));
            ++$reported;
        }

        return implode("\n", $lines);
    }
}
