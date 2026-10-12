<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbox\Command;

use Hyperf\Apex\Outbox\Service\OutboxDeliveryService;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Contract\ConfigInterface;

/**
 * 清理已成功的 Outbox 历史事件。
 *
 * 只清「成功」这一类：失败与死信永不自动删除，它们需要人工看过之后再决定重放还是放弃。
 * `outbox.retention_days` 为 0 时什么都不做。
 *
 * 清理刻意不放进常驻消费进程：投递要的是低延迟，清理要的是低频批量，
 * 两者节奏不同，混在一个循环里会让其中一个迁就另一个。应用把它挂到 crontab 即可。
 */
#[Command(name: 'outbox:prune', description: '清理超出保留窗口的已成功发件箱事件。')]
final class OutboxPruneCommand extends HyperfCommand
{
    public function __construct(
        private readonly OutboxDeliveryService $delivery,
        private readonly ConfigInterface $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ((int) $this->config->get('outbox.retention_days', 0) <= 0) {
            $this->info('outbox.retention_days 为 0，未开启清理（失败与死信永不自动删除）。');

            return self::SUCCESS;
        }

        $this->info(sprintf('已清理 %d 条成功状态的 Outbox 事件。', $this->delivery->prune()));

        return self::SUCCESS;
    }
}
