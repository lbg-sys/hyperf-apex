<?php

declare(strict_types=1);

/*
 * 发布前的版本一致性断言：包间约束与 branch-alias 的主版本必须与本次 tag 一致。
 *
 * 起因：v3.0.0 打 tag 时，6 个包的 hyperf-apex/* 约束还停在 ^2.0、branch-alias 还写着
 * 2.0.x-dev。`^2.0` 的语义是 `>=2.0.0 <3.0.0`，所以装 hyperf-apex/apidoc:^3.0 时
 * composer 会去解析并安装 2.x 的 core —— 而 3.x 的代码依赖 3.x 的 core API，
 * 结果是装不上或运行期 Class not found。`composer validate` 只校验单个文件是否合法，
 * 查不出这类跨文件不一致，所以在这里挡一道。
 *
 * 用法：php .github/scripts/verify-package-versions.php v3.0.0
 * 退出码：0 通过；1 不一致；2 参数不合法。
 */

$tag = $argv[1] ?? '';
if (preg_match('/^v?(\d+)\.\d+\.\d+$/u', $tag, $matched) !== 1) {
    fwrite(STDERR, "用法：verify-package-versions.php <tag>，例如 v3.0.0\n");
    exit(2);
}

$major = (int) $matched[1];
$root = dirname(__DIR__, 2);

$files = [$root . '/composer.json'];
foreach (glob($root . '/packages/*/composer.json') ?: [] as $packageFile) {
    $files[] = $packageFile;
}

/** @var list<string> $errors */
$errors = [];

foreach ($files as $file) {
    $label = str_replace($root . '/', '', $file);
    $contents = file_get_contents($file);
    if ($contents === false) {
        fwrite(STDERR, sprintf('无法读取 %s。' . "\n", $label));
        exit(2);
    }

    try {
        /** @var array<string, mixed> $json */
        $json = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $errors[] = sprintf('%s 不是合法 JSON：%s', $label, $exception->getMessage());
        continue;
    }

    /** @var array<string, mixed> $requires */
    $requires = $json['require'] ?? [];
    foreach ($requires as $package => $constraint) {
        // 只校验仓库自己的包：hyperf/* 等第三方约束与本次 tag 无关。
        if (! str_starts_with((string) $package, 'hyperf-apex/')) {
            continue;
        }

        if (preg_match('/^\^(\d+)\./u', (string) $constraint, $parsed) !== 1 || (int) $parsed[1] !== $major) {
            $errors[] = sprintf(
                '%s：require[%s] 是 %s，主版本应为 %d（`^%d.0`）。',
                $label,
                $package,
                (string) $constraint,
                $major,
                $major
            );
        }
    }

    /** @var array<string, mixed> $aliases */
    $aliases = $json['extra']['branch-alias'] ?? [];
    foreach ($aliases as $branch => $alias) {
        if (preg_match('/^(\d+)\./u', (string) $alias, $parsed) !== 1 || (int) $parsed[1] !== $major) {
            $errors[] = sprintf(
                '%s：branch-alias[%s] 是 %s，主版本应为 %d（`%d.0.x-dev`）。',
                $label,
                (string) $branch,
                (string) $alias,
                $major,
                $major
            );
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "发布前版本一致性检查失败：\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf("版本一致性检查通过：%d 个 composer.json 的主版本均为 %d。\n", count($files), $major));
