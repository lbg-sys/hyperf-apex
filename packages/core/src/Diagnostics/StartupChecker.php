<?php

declare(strict_types=1);

namespace Hyperf\Apex\Diagnostics;

use Hyperf\Apex\Diagnostics\Contract\CheckerInterface;
use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;

use function Hyperf\Support\env;

/**
 * 启动期配置与环境规则检查器。
 *
 * 负责在服务监听端口前执行通用必填项与环境变量探测，支持外部通过 CheckerInterface 自定义扩展。
 */
final class StartupChecker implements CheckerInterface
{
    /** @var list<CheckerInterface> */
    private array $customCheckers = [];

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly ?ContainerInterface $container = null,
    ) {
    }

    /**
     * 动态注册自定义检查器。
     */
    public function registerChecker(CheckerInterface $checker): self
    {
        $this->customCheckers[] = $checker;

        return $this;
    }

    /**
     * 执行全部启动校验。
     *
     * @return list<string> 失败原因列表
     */
    public function check(): array
    {
        $errors = [
            ...$this->checkRequiredConfigs(),
            ...$this->checkRequiredEnvs(),
        ];

        $configuredCheckers = $this->config->get('startup_checks.checkers', []);
        if (is_array($configuredCheckers)) {
            foreach ($configuredCheckers as $checkerClass) {
                if (is_string($checkerClass) && class_exists($checkerClass)) {
                    $checkerInstance = $this->container?->get($checkerClass) ?? new $checkerClass();
                    if ($checkerInstance instanceof CheckerInterface) {
                        $errors = [...$errors, ...$checkerInstance->check()];
                    }
                }
            }
        }

        foreach ($this->customCheckers as $checker) {
            $errors = [...$errors, ...$checker->check()];
        }

        return $errors;
    }

    /**
     * 逐条执行 startup_checks.required 声明的必填配置项。
     *
     * @return list<string>
     */
    private function checkRequiredConfigs(): array
    {
        $rules = $this->config->get('startup_checks.required', []);
        if (! is_array($rules)) {
            return ['config startup_checks.required 必须是校验规则数组。'];
        }

        $errors = [];
        foreach ($rules as $rule) {
            if (! is_array($rule) || ! isset($rule['path'])) {
                continue;
            }
            $path = (string) $rule['path'];
            if ($this->isConfigured($this->config->get($path, null))) {
                continue;
            }
            $errors[] = sprintf(
                '%s 未配置：%s。请在配置或 .env 中补全后重新启动。',
                (string) ($rule['env'] ?? $path),
                rtrim((string) ($rule['hint'] ?? ''), '。'),
            );
        }

        return $errors;
    }

    /**
     * 值是否算「已配置」。
     *
     * 只有 null 与空字符串算未配置：`false`、`0`、空数组都是合法取值，不能因为 `(string)`
     * 转换后是空串（`(string) false === ''`）就把它们判成没配 —— 那会让服务拒绝启动，
     * 且报的是「某某项未配置」，与真实原因（配了 false）完全对不上。
     */
    private function isConfigured(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        return ! is_string($value) || trim($value) !== '';
    }

    /**
     * 逐条执行 startup_checks.required_envs 声明的必填环境变量。
     *
     * @return list<string>
     */
    private function checkRequiredEnvs(): array
    {
        $rules = $this->config->get('startup_checks.required_envs', []);
        if (! is_array($rules)) {
            return ['config startup_checks.required_envs 必须是环境变量规则数组。'];
        }

        $errors = [];
        foreach ($rules as $name => $hint) {
            if (trim((string) env((string) $name, '')) !== '') {
                continue;
            }
            $errors[] = sprintf(
                '环境变量 %s 未配置：%s。请在 .env 中配置后重新启动。',
                (string) $name,
                rtrim((string) $hint, '。'),
            );
        }

        return $errors;
    }
}
