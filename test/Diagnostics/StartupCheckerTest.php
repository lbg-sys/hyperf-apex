<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Diagnostics;

use Hyperf\Apex\Diagnostics\Contract\CheckerInterface;
use Hyperf\Apex\Diagnostics\StartupChecker;
use Hyperf\Config\Config;
use PHPUnit\Framework\TestCase;

final class StartupCheckerTest extends TestCase
{
    public function testCheckRequiredConfigsPass(): void
    {
        $config = new Config([
            'startup_checks' => [
                'required' => [
                    [
                        'path' => 'app_name',
                        'env' => 'APP_NAME',
                        'hint' => '应用名称必填',
                    ],
                ],
            ],
            'app_name' => 'HyperfCoreTest',
        ]);

        $checker = new StartupChecker($config);
        $errors = $checker->check();

        $this->assertSame([], $errors);
    }

    public function testCheckRequiredConfigsFail(): void
    {
        $config = new Config([
            'startup_checks' => [
                'required' => [
                    [
                        'path' => 'missing_key',
                        'env' => 'MISSING_KEY',
                        'hint' => '必须填写',
                    ],
                ],
            ],
        ]);

        $checker = new StartupChecker($config);
        $errors = $checker->check();

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('MISSING_KEY 未配置', $errors[0]);
    }

    public function testBooleanFalseCountsAsConfigured(): void
    {
        // false / 0 都是合法取值：不能因为 (string) false === '' 就把它们判成「未配置」而拒绝启动，
        // 那样报出来的原因是「某某项未配置」，与真实情况（配了 false）完全对不上。
        $config = new Config([
            'startup_checks' => [
                'required' => [
                    [
                        'path' => 'feature_enabled',
                        'env' => 'FEATURE_ENABLED',
                        'hint' => '功能开关',
                    ],
                    [
                        'path' => 'retention_days',
                        'env' => 'RETENTION_DAYS',
                        'hint' => '保留天数',
                    ],
                ],
            ],
            'feature_enabled' => false,
            'retention_days' => 0,
        ]);

        $this->assertSame([], (new StartupChecker($config))->check());
    }

    public function testBlankStringStillCountsAsMissing(): void
    {
        $config = new Config([
            'startup_checks' => [
                'required' => [
                    [
                        'path' => 'app_name',
                        'env' => 'APP_NAME',
                        'hint' => '应用名称',
                    ],
                ],
            ],
            'app_name' => '   ',
        ]);

        $errors = (new StartupChecker($config))->check();

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('APP_NAME 未配置', $errors[0]);
    }

    public function testCustomChecker(): void
    {
        $config = new Config([]);
        $checker = new StartupChecker($config);

        $custom = new class implements CheckerInterface {
            public function check(): array
            {
                return ['自定义探测器发现异常'];
            }
        };

        $checker->registerChecker($custom);
        $errors = $checker->check();

        $this->assertSame(['自定义探测器发现异常'], $errors);
    }
}
