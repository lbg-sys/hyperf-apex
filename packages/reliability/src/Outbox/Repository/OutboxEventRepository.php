<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbox\Repository;

use Hyperf\Apex\Database\Repository\AbstractRepository;
use Hyperf\Apex\Outbox\DTO\OutboxClaimDTO;
use Hyperf\Apex\Outbox\DTO\OutboxMessageDTO;
use Hyperf\Apex\Outbox\Enum\OutboxEventStatus;
use Hyperf\Apex\Outbox\Model\OutboxEvent;
use Hyperf\Apex\Outbox\Repository\Contract\OutboxEventRepositoryInterface;
use Hyperf\DbConnection\Db;
use InvalidArgumentException;

/** @extends AbstractRepository<OutboxEvent> */
final class OutboxEventRepository extends AbstractRepository implements OutboxEventRepositoryInterface
{
    protected string $modelClass = OutboxEvent::class;

    public function create(array $data): OutboxEvent
    {
        return $this->newModel()->create($data);
    }

    public function find(int $id): ?OutboxEvent
    {
        return $this->query()->find($id);
    }

    public function findForKey(string $scopeKey, string $eventType, string $idempotencyKey): ?OutboxEvent
    {
        return $this->query()
            ->where('scope_key', $scopeKey)
            ->where('event_type', $eventType)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    public function claimDue(string $now, int $limit, string $lockToken, string $lockExpireTime): array
    {
        /*
         * 选行与置租约必须在同一个事务里：没有 SKIP LOCKED 时靠行锁互斥 ——
         * 后到的 worker 会阻塞到前一个提交，随后看到这些行已是 processing 且租约未到期，于是不再重复认领。
         * 加 SKIP LOCKED 只是省掉这次阻塞等待，语义不变。
         */
        return Db::transaction(function () use ($now, $limit, $lockToken, $lockExpireTime): array {
            $rows = $this->query()
                ->where(static function ($query) use ($now): void {
                    $query->where(static function ($query) use ($now): void {
                        $query->where('status', OutboxEventStatus::PENDING->value)
                            ->where(static function ($query) use ($now): void {
                                $query->whereNull('next_attempt_time')->orWhere('next_attempt_time', '<=', $now);
                            });
                    })->orWhere(static function ($query) use ($now): void {
                        // 认领它的 worker 失联了：租约到期后重新变成可认领。
                        $query->where('status', OutboxEventStatus::PROCESSING->value)
                            ->where('lock_expire_time', '<=', $now);
                    });
                })
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                return [];
            }

            $ids = [];
            foreach ($rows as $row) {
                $ids[] = (int) $row->id;
            }

            $this->query()->whereIn('id', $ids)->update([
                'status' => OutboxEventStatus::PROCESSING->value,
                'lock_token' => $lockToken,
                'lock_expire_time' => $lockExpireTime,
                /*
                 * 认领即消耗一次尝试。崩溃的 worker 也要算：否则一条会拖垮进程的毒消息
                 * 可以永远重试下去，而「尝试次数用尽」正是它进入死信的唯一出口。
                 */
                'attempt_count' => Db::raw('attempt_count + 1'),
                'update_time' => $now,
            ]);

            return array_map(
                // attempt_count 在上面自增过，内存里的模型还是认领前的值，这里补上本次。
                static fn (OutboxEvent $row): OutboxClaimDTO => new OutboxClaimDTO(
                    new OutboxMessageDTO(
                        id: (int) $row->id,
                        eventType: $row->event_type,
                        scopeKey: $row->scope_key,
                        idempotencyKey: $row->idempotency_key,
                        aggregateType: $row->aggregate_type,
                        aggregateId: $row->aggregate_id,
                        payload: $row->payload,
                        attemptCount: (int) $row->attempt_count + 1,
                        maxAttempts: (int) $row->max_attempts,
                    ),
                    $lockToken,
                ),
                $rows->all(),
            );
        });
    }

    public function markSucceeded(int $id, string $lockToken, ?string $resultSummary, string $now): bool
    {
        return $this->transition($id, $lockToken, [
            'status' => OutboxEventStatus::SUCCEEDED->value,
            'result_summary' => $resultSummary,
            'success_time' => $now,
        ], $now);
    }

    public function scheduleRetry(
        int $id,
        string $lockToken,
        string $failureType,
        ?string $error,
        string $nextAttemptTime,
        string $now,
    ): bool {
        return $this->transition($id, $lockToken, [
            'status' => OutboxEventStatus::PENDING->value,
            'last_failure_type' => $failureType,
            'last_error' => $error,
            'next_attempt_time' => $nextAttemptTime,
        ], $now);
    }

    public function markTerminal(
        int $id,
        string $lockToken,
        OutboxEventStatus $status,
        string $failureType,
        ?string $error,
        string $now,
    ): bool {
        if (! in_array($status, [OutboxEventStatus::FAILED, OutboxEventStatus::DEAD_LETTER], true)) {
            throw new InvalidArgumentException('Outbox 终态写入只接受 failed 或 dead_letter。');
        }

        return $this->transition($id, $lockToken, [
            'status' => $status->value,
            'last_failure_type' => $failureType,
            'last_error' => $error,
            'fail_time' => $now,
        ], $now);
    }

    public function replay(int $id, string $now): bool
    {
        $updated = $this->query()
            ->where('id', $id)
            ->whereIn('status', [OutboxEventStatus::FAILED->value, OutboxEventStatus::DEAD_LETTER->value])
            ->update([
                'status' => OutboxEventStatus::PENDING->value,
                // 重放给一次全新的尝试预算，否则旧快照会让它立刻又进死信。
                'attempt_count' => 0,
                'next_attempt_time' => null,
                'lock_token' => null,
                'lock_expire_time' => null,
                'last_failure_type' => null,
                'last_error' => null,
                'result_summary' => null,
                'fail_time' => null,
                'replay_count' => Db::raw('replay_count + 1'),
                'last_replay_time' => $now,
                'update_time' => $now,
            ]);

        return $updated === 1;
    }

    public function deleteSucceededBefore(string $before, int $limit): int
    {
        return $this->query()
            ->where('status', OutboxEventStatus::SUCCEEDED->value)
            ->where('success_time', '<=', $before)
            ->limit($limit)
            ->delete();
    }

    /**
     * 带 fencing 的状态流转。
     *
     * 条件里的令牌相等是防「旧 worker 覆盖新结果」的关键：租约过期后被接管的事件，
     * 令牌已经换成了接管者的，旧 worker 的写回会命中 0 行。
     *
     * @param array<string, mixed> $attributes
     */
    private function transition(int $id, string $lockToken, array $attributes, string $now): bool
    {
        $updated = $this->query()
            ->where('id', $id)
            ->where('status', OutboxEventStatus::PROCESSING->value)
            ->where('lock_token', $lockToken)
            ->update(array_merge($attributes, [
                // 释放租约：下次认领换新令牌，旧令牌从此写不动这一行。
                'lock_token' => null,
                'lock_expire_time' => null,
                'update_time' => $now,
            ]));

        return $updated === 1;
    }
}
