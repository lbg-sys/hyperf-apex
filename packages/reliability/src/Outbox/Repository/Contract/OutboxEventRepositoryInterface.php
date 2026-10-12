<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbox\Repository\Contract;

use Hyperf\Apex\Outbox\DTO\OutboxClaimDTO;
use Hyperf\Apex\Outbox\Enum\OutboxEventStatus;
use Hyperf\Apex\Outbox\Model\OutboxEvent;
use Hyperf\Database\Exception\UniqueConstraintViolationException;

interface OutboxEventRepositoryInterface
{
    /**
     * 插入一条事件。
     *
     * @param array<string, mixed> $data
     * @throws UniqueConstraintViolationException 同一「范围 + 类型 + 幂等键」已存在
     */
    public function create(array $data): OutboxEvent;

    public function find(int $id): ?OutboxEvent;

    public function findForKey(string $scopeKey, string $eventType, string $idempotencyKey): ?OutboxEvent;

    /**
     * 认领一批到期事件，并写入本次认领的租约。
     *
     * 到期 = 「待执行且已到 next_attempt_time」或「执行中但租约已过期（worker 失联）」。
     *
     * @return list<OutboxClaimDTO>
     */
    public function claimDue(string $now, int $limit, string $lockToken, string $lockExpireTime): array;

    /**
     * 续租：把当前认领的租约推后。返回 false 表示已不再持有该令牌（被别的 worker 接管了）。
     *
     * 一批事件共用认领时写下的那一个租约，而批内是逐条串行执行的 —— 处理到靠后的条目时
     * 原租约可能已经过期。执行 handler 之前必须确认租约还在，否则会把同一条事件的副作用
     * 执行两次（fencing 只拦得住写回，拦不住副作用）。
     */
    public function renewLease(int $id, string $lockToken, string $lockExpireTime, string $now): bool;

    /** 写回成功。令牌不匹配（已被接管）时返回 false 且不做任何写入。 */
    public function markSucceeded(int $id, string $lockToken, ?string $resultSummary, string $now): bool;

    /** 安排下一次尝试。令牌不匹配时返回 false。 */
    public function scheduleRetry(
        int $id,
        string $lockToken,
        string $failureType,
        ?string $error,
        string $nextAttemptTime,
        string $now,
    ): bool;

    /** 写入失败终态（永久失败或死信）。令牌不匹配时返回 false。 */
    public function markTerminal(
        int $id,
        string $lockToken,
        OutboxEventStatus $status,
        string $failureType,
        ?string $error,
        string $now,
    ): bool;

    /** 人工重放：把失败终态重置为待执行并给一次新的尝试预算。返回 false 表示当前状态不允许重放。 */
    public function replay(int $id, string $now): bool;

    /** 删除成功状态且早于指定时间的历史事件，返回删除条数。 */
    public function deleteSucceededBefore(string $before, int $limit): int;
}
