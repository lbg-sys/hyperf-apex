<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Outbox\Support;

use Exception;
use Hyperf\Apex\Outbox\DTO\OutboxClaimDTO;
use Hyperf\Apex\Outbox\DTO\OutboxMessageDTO;
use Hyperf\Apex\Outbox\Enum\OutboxEventStatus;
use Hyperf\Apex\Outbox\Model\OutboxEvent;
use Hyperf\Apex\Outbox\Repository\Contract\OutboxEventRepositoryInterface;
use Hyperf\Apex\Support\Time;
use Hyperf\Database\Exception\UniqueConstraintViolationException;

/**
 * 按仓储契约在内存里复刻 outbox_events，重点复刻三处判据：
 * 唯一键、到期判定（含租约过期重认领）、带令牌的写回。
 */
final class FakeOutboxEventRepository implements OutboxEventRepositoryInterface
{
    /** 测试辅助：renewLease() 收到的 id（按调用顺序）。 */
    public array $renewedIds = [];

    /** 测试辅助：false 表示本次已不再持有租约（被别的 worker 接管）。 */
    public bool $renewLeaseResult = true;

    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    private int $nextId = 1;

    public function create(array $data): OutboxEvent
    {
        foreach ($this->rows as $row) {
            if ($row['scope_key'] === $data['scope_key']
                && $row['event_type'] === $data['event_type']
                && $row['idempotency_key'] === $data['idempotency_key']) {
                throw new UniqueConstraintViolationException(
                    'insert into outbox_events (scope_key, event_type, idempotency_key) values (?, ?, ?)',
                    [$data['scope_key'], $data['event_type'], $data['idempotency_key']],
                    new Exception('duplicate entry'),
                );
            }
        }

        $id = $this->nextId++;
        $now = Time::now()->toDateTimeString();
        $this->rows[$id] = $data + [
            'id' => $id,
            'status' => OutboxEventStatus::PENDING->value,
            'attempt_count' => 0,
            'max_attempts' => 8,
            'next_attempt_time' => null,
            'lock_token' => null,
            'lock_expire_time' => null,
            'last_failure_type' => null,
            'last_error' => null,
            'result_summary' => null,
            'success_time' => null,
            'fail_time' => null,
            'replay_count' => 0,
            'last_replay_time' => null,
            'create_time' => $now,
            'update_time' => $now,
        ];

        return $this->model($id);
    }

    public function find(int $id): ?OutboxEvent
    {
        return isset($this->rows[$id]) ? $this->model($id) : null;
    }

    public function findForKey(string $scopeKey, string $eventType, string $idempotencyKey): ?OutboxEvent
    {
        foreach ($this->rows as $id => $row) {
            if ($row['scope_key'] === $scopeKey
                && $row['event_type'] === $eventType
                && $row['idempotency_key'] === $idempotencyKey) {
                return $this->model($id);
            }
        }

        return null;
    }

    public function claimDue(string $now, int $limit, string $lockToken, string $lockExpireTime): array
    {
        $claims = [];
        foreach (array_keys($this->rows) as $id) {
            if (count($claims) >= $limit) {
                break;
            }
            if (! $this->isDue($this->rows[$id], $now)) {
                continue;
            }

            $this->rows[$id]['status'] = OutboxEventStatus::PROCESSING->value;
            $this->rows[$id]['lock_token'] = $lockToken;
            $this->rows[$id]['lock_expire_time'] = $lockExpireTime;
            $this->rows[$id]['attempt_count'] = (int) $this->rows[$id]['attempt_count'] + 1;

            $claims[] = new OutboxClaimDTO($this->message($id), $lockToken);
        }

        return $claims;
    }

    public function renewLease(int $id, string $lockToken, string $lockExpireTime, string $now): bool
    {
        $this->renewedIds[] = $id;

        $row = $this->rows[$id] ?? null;
        if (! $this->renewLeaseResult
            || $row === null
            || $row['status'] !== OutboxEventStatus::PROCESSING->value
            || $row['lock_token'] !== $lockToken) {
            return false;
        }

        $this->rows[$id]['lock_expire_time'] = $lockExpireTime;
        $this->rows[$id]['update_time'] = $now;

        return true;
    }

    public function markSucceeded(int $id, string $lockToken, ?string $resultSummary, string $now): bool
    {
        return $this->transition($id, $lockToken, [
            'status' => OutboxEventStatus::SUCCEEDED->value,
            'result_summary' => $resultSummary,
            'success_time' => $now,
        ]);
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
        ]);
    }

    public function markTerminal(
        int $id,
        string $lockToken,
        OutboxEventStatus $status,
        string $failureType,
        ?string $error,
        string $now,
    ): bool {
        return $this->transition($id, $lockToken, [
            'status' => $status->value,
            'last_failure_type' => $failureType,
            'last_error' => $error,
            'fail_time' => $now,
        ]);
    }

    public function replay(int $id, string $now): bool
    {
        $row = $this->rows[$id] ?? null;
        if ($row === null || ! in_array($row['status'], [OutboxEventStatus::FAILED->value, OutboxEventStatus::DEAD_LETTER->value], true)) {
            return false;
        }

        $this->rows[$id] = array_merge($row, [
            'status' => OutboxEventStatus::PENDING->value,
            'attempt_count' => 0,
            'next_attempt_time' => null,
            'lock_token' => null,
            'lock_expire_time' => null,
            'last_failure_type' => null,
            'last_error' => null,
            'result_summary' => null,
            'fail_time' => null,
            'replay_count' => (int) $row['replay_count'] + 1,
            'last_replay_time' => $now,
        ]);

        return true;
    }

    public function deleteSucceededBefore(string $before, int $limit): int
    {
        $deleted = 0;
        foreach ($this->rows as $id => $row) {
            if ($deleted >= $limit) {
                break;
            }
            if ($row['status'] !== OutboxEventStatus::SUCCEEDED->value) {
                continue;
            }
            if ($row['success_time'] === null || $row['success_time'] > $before) {
                continue;
            }

            unset($this->rows[$id]);
            ++$deleted;
        }

        return $deleted;
    }

    /** 测试辅助：直接读原始行。 */
    public function raw(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    /** 测试辅助：直接改行的字段（模拟时间流逝、租约被接管等）。 */
    public function mutate(int $id, array $attributes): void
    {
        $this->rows[$id] = array_merge($this->rows[$id], $attributes);
    }

    public function count(): int
    {
        return count($this->rows);
    }

    private function isDue(array $row, string $now): bool
    {
        if ($row['status'] === OutboxEventStatus::PENDING->value) {
            return $row['next_attempt_time'] === null || $row['next_attempt_time'] <= $now;
        }

        // 认领它的 worker 失联：租约过期后重新可认领。
        if ($row['status'] === OutboxEventStatus::PROCESSING->value) {
            return $row['lock_expire_time'] !== null && $row['lock_expire_time'] <= $now;
        }

        return false;
    }

    private function transition(int $id, string $lockToken, array $attributes): bool
    {
        $row = $this->rows[$id] ?? null;
        if ($row === null
            || $row['status'] !== OutboxEventStatus::PROCESSING->value
            || $row['lock_token'] !== $lockToken) {
            return false;
        }

        $this->rows[$id] = array_merge($row, $attributes, [
            'lock_token' => null,
            'lock_expire_time' => null,
            'update_time' => Time::now()->toDateTimeString(),
        ]);

        return true;
    }

    private function message(int $id): OutboxMessageDTO
    {
        $row = $this->rows[$id];

        return new OutboxMessageDTO(
            id: $id,
            eventType: (string) $row['event_type'],
            scopeKey: (string) $row['scope_key'],
            idempotencyKey: (string) $row['idempotency_key'],
            aggregateType: $row['aggregate_type'] === null ? null : (string) $row['aggregate_type'],
            aggregateId: $row['aggregate_id'] === null ? null : (string) $row['aggregate_id'],
            payload: (array) $row['payload'],
            attemptCount: (int) $row['attempt_count'],
            maxAttempts: (int) $row['max_attempts'],
        );
    }

    private function model(int $id): OutboxEvent
    {
        $model = new OutboxEvent($this->rows[$id]);
        // 时间列的读写默认要问连接要格式，替身没有连接，这里直接给死格式。
        $model->setDateFormat('Y-m-d H:i:s');
        $model->id = $id;

        return $model;
    }
}
