<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\ApiDoc\Support;

use ErrorException;
use Hyperf\Command\Command;
use Swoole\Coroutine;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * 在协程里驱动一个 Hyperf 命令，返回 `[退出码, 输出文本]`。
 *
 * 为什么必须自己套一层协程：Hyperf 的 `Command::run()` 在协程**外**构造 `SymfonyStyle`，
 * 而 Symfony 取终端宽度时会走 `proc_open`；测试引导里开着
 * `Runtime::enableCoroutine(SWOOLE_HOOK_ALL)`，这条组合在协程外会让进程直接退出
 * （没有任何输出、退出码 255），表现为「测试莫名其妙什么都没跑」。
 *
 * 生产上命令同样由 `Coroutine\run()` 驱动（Hyperf 在 `execute()` 里做的），
 * 所以这里补的是同一层，不是为测试开的后门。
 */
final class CommandRunner
{
    /**
     * @return array{0: int, 1: string} 退出码与命令输出
     */
    public static function run(Command $command, InputInterface $input): array
    {
        $exit = null;
        $output = new BufferedOutput();
        $failure = null;

        Coroutine\run(static function () use ($command, $input, $output, &$exit, &$failure): void {
            /*
             * 协程里必须自己接管错误处理：PHPUnit 的错误处理器要靠调用栈上的 TestCase 认领错误，
             * 而协程的栈上没有它 —— 一个警告就会变成 NoTestCaseObjectOnCallStackException，
             * 把整个测试进程带走（现象是「什么都没跑」）。这里把警告升级成异常并带出协程，
             * 让它显示成一条正常的测试失败。
             */
            set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
                // 被 @ 抑制的不算错误：命令里有刻意抑制并自行处理的分支（如 mkdir 失败）。
                if ((error_reporting() & $severity) === 0) {
                    return false;
                }

                throw new ErrorException($message, 0, $severity, $file, $line);
            });

            try {
                $exit = $command->run($input, $output);
            } catch (Throwable $throwable) {
                $failure = $throwable;
            } finally {
                restore_error_handler();
            }
        });

        if ($failure !== null) {
            throw $failure;
        }

        return [(int) $exit, $output->fetch()];
    }

    /**
     * 无参数命令的快捷入口。
     *
     * @return array{0: int, 1: string}
     */
    public static function runBare(Command $command): array
    {
        return self::run($command, new ArrayInput([]));
    }
}
