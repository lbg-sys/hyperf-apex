<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbox\Process;

use Hyperf\Apex\Outbox\Service\OutboxDeliveryService;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\Annotation\Process;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Outbox 消费进程。
 *
 * 由注解自动注册，应用不必把它写进 `config/autoload/processes.php`；
 * 默认不启用：`outbox.process.enabled=false` 时 `isEnable()` 返回 false，进程根本不会被孵化。
 * 要开始消费，把 `OUTBOX_PROCESS_ENABLED` 设为 true 即可。
 *
 * 进程在 master 里孵化一次（`BeforeMainServerStart`），不是每个 worker 一份；
 * 即使被部署成多份，认领租约也保证同一条事件只会被一个 worker 投递。
 * 多实例部署时每个实例都会有这样一个进程，行的租约同样保证不会重复投递。
 */
#[Process(name: 'outbox-consume')]
final class OutboxConsumeProcess extends AbstractProcess
{
    private readonly LoggerInterface $logger;

    public function __construct(
        ContainerInterface $container,
        private readonly OutboxDeliveryService $delivery,
        private readonly ConfigInterface $config,
        LoggerFactory $loggerFactory,
    ) {
        parent::__construct($container);

        $this->logger = $loggerFactory->get('outbox', 'error');
    }

    public function isEnable($server): bool
    {
        return (bool) $this->config->get('outbox.enabled', true)
            && (bool) $this->config->get('outbox.process.enabled', false);
    }

    public function handle(): void
    {
        $interval = max(1, (int) $this->config->get('outbox.process.poll_interval_seconds', 2));
        $batchSize = max(1, (int) $this->config->get('outbox.process.batch_size', 50));

        // 常驻循环：没有退出条件，进程由 Swoole 在收到停止信号时结束。
        while (true) {
            $processed = 0;
            try {
                $processed = $this->delivery->deliverBatch($batchSize);
            } catch (Throwable $throwable) {
                // 单轮失败（数据库抖断、连接池耗尽等）不能让消费进程退出：记日志后等下一轮。
                $this->logger->error('Outbox 消费轮次失败。', ['exception' => $throwable]);
            }

            // 取到满批说明还有积压，立刻再来一轮；否则按轮询间隔歇一下，
            // 不然吞吐会被压在 batch_size / poll_interval 上。
            if ($processed < $batchSize) {
                sleep($interval);
            }
        }
    }
}
