<?php

declare(strict_types=1);

namespace Hyperf\Apex\Outbox\DTO;

use Hyperf\Apex\DTO\DTOHydrator;

final readonly class OutboxMessageDTO
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $id,
        public string $eventType,
        public string $scopeKey,
        public string $idempotencyKey,
        public ?string $aggregateType,
        public ?string $aggregateId,
        public array $payload,
        public int $attemptCount,
        public int $maxAttempts,
    ) {
    }

    /**
     * 把事件载荷水合成强类型 DTO。
     *
     * 载荷在库里是 JSON（读回为 array），handler 按自己期望的载荷形状在这里转型。
     * 只做类型转换、不校验 —— 载荷是应用自己发布的数据，不是外部输入。
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function payloadAs(string $class): object
    {
        return DTOHydrator::hydrate($class, $this->payload);
    }
}
