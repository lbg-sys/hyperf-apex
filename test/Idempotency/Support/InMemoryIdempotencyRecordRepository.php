<?php

declare(strict_types=1);

namespace Hyperf\Apex\Test\Idempotency\Support;

use Exception;
use Hyperf\Apex\Idempotency\Enum\IdempotencyStatus;
use Hyperf\Apex\Idempotency\Model\IdempotencyRecord;
use Hyperf\Apex\Idempotency\Repository\Contract\IdempotencyRecordRepositoryInterface;
use Hyperf\Database\Exception\UniqueConstraintViolationException;

/**
 * 按仓储契约在内存里复刻一张幂等记录表，重点复刻两处条件写入的判据。
 *
 * 真实实现里 `create()` 的 CAS 在唯一键约束、`restart()` / `complete()` 的 CAS 在
 * WHERE 子句里；这里用内存数组复刻同样的语义。额外带几个测试钩子，用于制造
 * 竞态（`restart` 落败）与清理批量（`deleteExpired` 的返回序列）。
 */
final class InMemoryIdempotencyRecordRepository implements IdempotencyRecordRepositoryInterface
{
    /** 置为 true 后，restart() 无条件返回 false，模拟「接管竞态落败」。 */
    public bool $failRestart = false;

    /** deleteExpired 的返回序列（先入先出）；为空时返回 0。 */
    public array $deleteExpiredQueue = [];

    public int $deleteExpiredCalls = 0;

    /** @var array<int, IdempotencyRecord> */
    private array $records = [];

    private int $nextId = 1;

    /** @param array<string, mixed> $data */
    public function create(array $data): IdempotencyRecord
    {
        if ($this->find($data['caller_hash'], $data['endpoint'], $data['request_id']) !== null) {
            throw new UniqueConstraintViolationException(
                'insert into idempotency_records (caller_hash, endpoint, request_id) values (?, ?, ?)',
                [$data['caller_hash'], $data['endpoint'], $data['request_id']],
                new Exception('duplicate entry'),
            );
        }

        $record = new IdempotencyRecord($data);
        // 时间列的读写默认要问连接要格式，替身没有连接，这里直接给死格式。
        $record->setDateFormat('Y-m-d H:i:s');
        $record->id = $this->nextId++;
        // 真实 save() 会落 create_time / update_time，替身要手动补上：
        // 否则「update_time 为 null」会让未超时的 PROCESSING 记录被误判成可接管。
        $now = date('Y-m-d H:i:s');
        $record->create_time = $now;
        $record->update_time = $now;
        $this->records[$record->id] = $record;

        return $record;
    }

    public function find(string $callerHash, string $endpoint, string $requestId): ?IdempotencyRecord
    {
        foreach ($this->records as $record) {
            if ($record->caller_hash === $callerHash
                && $record->endpoint === $endpoint
                && $record->request_id === $requestId) {
                return $record;
            }
        }

        return null;
    }

    public function restart(
        int $id,
        string $requestHash,
        string $lockToken,
        string $expireTime,
        string $now,
        string $staleBefore,
    ): bool {
        $record = $this->records[$id] ?? null;
        if ($record === null || $this->failRestart || ! $this->takeoverAllowed($record, $now, $staleBefore)) {
            return false;
        }

        $record->request_hash = $requestHash;
        $record->status = IdempotencyStatus::PROCESSING->value;
        $record->lock_token = $lockToken;
        $record->response_status = null;
        $record->response_content_type = null;
        $record->response_body = null;
        $record->expire_time = $expireTime;
        $record->update_time = $now;

        return true;
    }

    public function complete(
        int $id,
        string $lockToken,
        IdempotencyStatus $status,
        int $responseStatus,
        string $responseContentType,
        string $responseBody,
    ): bool {
        $record = $this->records[$id] ?? null;
        if ($record === null
            || $record->status !== IdempotencyStatus::PROCESSING->value
            || $record->lock_token !== $lockToken) {
            return false;
        }

        $record->status = $status->value;
        $record->response_status = $responseStatus;
        $record->response_content_type = $responseContentType;
        $record->response_body = $responseBody;

        return true;
    }

    public function deleteExpired(string $now, int $limit): int
    {
        ++$this->deleteExpiredCalls;

        return $this->deleteExpiredQueue === [] ? 0 : (int) array_shift($this->deleteExpiredQueue);
    }

    /** 把记录置为已过期，模拟执行进程失联。 */
    public function expire(int $id): void
    {
        $this->records[$id]->expire_time = date('Y-m-d H:i:s', time() - 1);
    }

    /** 把记录改造成「终态但缺响应体」，模拟数据被部分写入。 */
    public function corruptResponse(int $id): void
    {
        $this->records[$id]->status = IdempotencyStatus::SUCCEEDED->value;
        $this->records[$id]->response_status = 200;
        $this->records[$id]->response_body = null;
    }

    /** 读取落库的响应体。 */
    public function responseBody(int $id): ?string
    {
        return $this->records[$id]->response_body;
    }

    private function takeoverAllowed(IdempotencyRecord $record, string $now, string $staleBefore): bool
    {
        // update_time 是日期列，读属性会返回 Carbon；CAS 判据要的是库里的字面值，取原始属性。
        $updatedAt = (string) ($record->getAttributes()['update_time'] ?? '');

        return $record->expire_time <= $now
            || ($record->status === IdempotencyStatus::PROCESSING->value && $updatedAt <= $staleBefore);
    }
}
