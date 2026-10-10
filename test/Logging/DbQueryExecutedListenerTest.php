<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Logging;

use Closure;
use Generator;
use Hyperf\Apex\Listener\DbQueryExecutedListener;
use Hyperf\Config\Config;
use Hyperf\Contract\ContainerInterface;
use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\Events\QueryExecuted;
use Hyperf\Database\Query\Builder;
use Hyperf\Database\Query\Expression;
use Hyperf\Logger\LoggerFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * SQL 日志监听器的门控契约。
 *
 * 生产只留「写操作 + 慢查询」（sql_log.full=false），判断放在最前面 —— 不记录的查询
 * 连 bindings 字符串拼接都省掉。命名绑定（:name）不做展开（拼接前提是位置绑定与 ? 一一对应）。
 */
final class DbQueryExecutedListenerTest extends TestCase
{
    public function testFullModeLogsEverything(): void
    {
        [$listener, $logger] = $this->listener(['full' => true]);

        $listener->process($this->event('select * from users'));

        $this->assertCount(1, $logger->records);
    }

    public function testSlowQueryIsLoggedWhenFullIsOff(): void
    {
        [$listener, $logger] = $this->listener(['full' => false, 'slow_ms' => 200]);
        $listener->process($this->event('select * from users', time: 300.0));

        $this->assertCount(1, $logger->records);
    }

    public function testFastReadIsNotLoggedWhenFullIsOff(): void
    {
        [$listener, $logger] = $this->listener(['full' => false, 'slow_ms' => 200]);
        $listener->process($this->event('select * from users', time: 10.0));

        $this->assertSame([], $logger->records);
    }

    public function testWriteIsLoggedEvenWhenFast(): void
    {
        [$listener, $logger] = $this->listener(['full' => false, 'slow_ms' => 200]);
        $listener->process($this->event('update users set name = ? where id = ?', time: 5.0));

        $this->assertCount(1, $logger->records);
    }

    public function testAssocBindingsAreNotInterpolated(): void
    {
        [$listener, $logger] = $this->listener(['full' => true]);
        $listener->process($this->event('select * from users where id = :id', [':id' => 5]));

        // 命名绑定不做展开：原始 SQL 原样输出，不强行拼位置占位符。
        $this->assertStringContainsString('select * from users where id = :id', $logger->records[0][1]);
    }

    public function testPositionalBindingsAreInterpolated(): void
    {
        [$listener, $logger] = $this->listener(['full' => true]);
        $listener->process($this->event('update users set password_hash = ?, name = ?', ['secret', 'alice']));

        $this->assertStringContainsString("password_hash = '[redacted]', name = 'alice'", $logger->records[0][1]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{0: DbQueryExecutedListener, 1: CapturingLogger}
     */
    private function listener(array $config = []): array
    {
        $logger = new CapturingLogger();
        $container = new class($logger) implements ContainerInterface {
            public function __construct(private readonly CapturingLogger $logger)
            {
            }

            public function get(string $id): mixed
            {
                return $id === LoggerFactory::class ? new CapturingLoggerFactory($this->logger) : null;
            }

            public function has(string $id): bool
            {
                return $id === LoggerFactory::class;
            }

            public function make(string $name, array $parameters = []): mixed
            {
                throw new RuntimeException('not used');
            }

            public function set(string $name, mixed $entry): void
            {
            }

            public function unbind(string $name): void
            {
            }

            public function define(string $name, mixed $definition): void
            {
            }
        };

        $listener = new DbQueryExecutedListener($container, new Config([
            'sql_log' => array_merge(['full' => true, 'slow_ms' => 200, 'writes' => true], $config),
        ]));

        return [$listener, $logger];
    }

    private function event(string $sql, array $bindings = [], ?float $time = null): QueryExecuted
    {
        return new QueryExecuted($sql, $bindings, $time, new FakeConnection());
    }
}

final class CapturingLogger implements LoggerInterface
{
    /** @var list<array{0: string, 1: string, 2: array}> */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message, $context];
    }

    public function emergency($message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }
}

final class CapturingLoggerFactory extends LoggerFactory
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function get(string $name = 'hyperf', ?string $channel = null): LoggerInterface
    {
        return $this->logger;
    }
}

/** QueryExecuted 构造需要一个连接实例，这里只补 getName()，其余方法都用不到。 */
final class FakeConnection implements ConnectionInterface
{
    public function getName(): ?string
    {
        return 'default';
    }

    public function table($table): Builder
    {
        throw new RuntimeException('not used');
    }

    public function raw($value): Expression
    {
        throw new RuntimeException('not used');
    }

    public function selectOne(string $query, array $bindings = [], bool $useReadPdo = true)
    {
        throw new RuntimeException('not used');
    }

    public function select(string $query, array $bindings = [], bool $useReadPdo = true): array
    {
        throw new RuntimeException('not used');
    }

    public function cursor(string $query, array $bindings = [], bool $useReadPdo = true): Generator
    {
        throw new RuntimeException('not used');
    }

    public function insert(string $query, array $bindings = []): bool
    {
        throw new RuntimeException('not used');
    }

    public function update(string $query, array $bindings = []): int
    {
        throw new RuntimeException('not used');
    }

    public function delete(string $query, array $bindings = []): int
    {
        throw new RuntimeException('not used');
    }

    public function statement(string $query, array $bindings = []): bool
    {
        throw new RuntimeException('not used');
    }

    public function affectingStatement(string $query, array $bindings = []): int
    {
        throw new RuntimeException('not used');
    }

    public function unprepared(string $query): bool
    {
        throw new RuntimeException('not used');
    }

    public function prepareBindings(array $bindings): array
    {
        throw new RuntimeException('not used');
    }

    public function transaction(Closure $callback, int $attempts = 1)
    {
        throw new RuntimeException('not used');
    }

    public function beginTransaction(): void
    {
        throw new RuntimeException('not used');
    }

    public function commit(): void
    {
        throw new RuntimeException('not used');
    }

    public function rollBack(): void
    {
        throw new RuntimeException('not used');
    }

    public function transactionLevel(): int
    {
        throw new RuntimeException('not used');
    }

    public function pretend(Closure $callback): array
    {
        throw new RuntimeException('not used');
    }
}
