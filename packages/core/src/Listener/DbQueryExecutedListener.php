<?php

declare(strict_types=1);

namespace Hyperf\Apex\Listener;

use Hyperf\Apex\Logging\LoggerChannels;
use Hyperf\Apex\Support\SqlBindingRedactor;
use Hyperf\Collection\Arr;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\Events\QueryExecuted;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Logger\LoggerFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

#[Listener]
class DbQueryExecutedListener implements ListenerInterface
{
    private LoggerInterface $logger;

    private bool $full;

    private float $slowMs;

    private bool $writes;

    public function __construct(ContainerInterface $container, ConfigInterface $config)
    {
        $this->logger = LoggerChannels::resolve($container->get(LoggerFactory::class), 'sql', 'sql');
        $this->full = (bool) $config->get('sql_log.full', true);
        $this->slowMs = (float) $config->get('sql_log.slow_ms', 200);
        $this->writes = (bool) $config->get('sql_log.writes', true);
    }

    public function listen(): array
    {
        return [
            QueryExecuted::class,
        ];
    }

    public function process(object $event): void
    {
        if (! $event instanceof QueryExecuted || ! $this->shouldLog($event)) {
            return;
        }

        $sql = $event->sql;
        if (! Arr::isAssoc($event->bindings)) {
            // 命名绑定（:name）不做展开：拼接前提是位置绑定与 `?` 一一对应。
            $sql = SqlBindingRedactor::interpolate($sql, $event->bindings);
        }

        $this->logger->info(sprintf('[%s] %s', $event->time, $sql));
    }

    /**
     * 生产只留「写操作 + 慢查询」，做法见 config/autoload/sql_log.php 的说明。
     *
     * 判断放在最前面：不记录的查询连 bindings 字符串拼接都省掉，那是每查询一次的 CPU 开销。
     */
    private function shouldLog(QueryExecuted $event): bool
    {
        if ($this->full) {
            return true;
        }

        if ($this->slowMs > 0 && ($event->time ?? 0.0) >= $this->slowMs) {
            return true;
        }

        return $this->writes && preg_match('/^\s*(?:insert|update|delete|replace)\b/i', $event->sql) === 1;
    }
}
