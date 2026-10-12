<?php

declare(strict_types=1);

namespace Hyperf\Apex\Http\ArgumentResolver;

use Hyperf\Apex\DTO\DTOHydrator;
use Hyperf\Apex\DTO\Metadata\DTORuleFactory;
use Hyperf\Context\ResponseContext;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\Validation\ValidationException;

final class RequestDataHydrator
{
    public function __construct(private readonly ValidatorFactoryInterface $validatorFactory)
    {
    }

    /**
     * 校验并水合成 DTO 实例。
     *
     * 校验失败时带的是可空的响应对象：HTTP 请求期一定拿得到 ResponseContext，
     * 而 WebSocket 的消息协程里没有它，get() 会因返回类型不符直接 TypeError ——
     * 于是「参数没填对」会变成 500。这里用 getOrNull()，让非 HTTP 调用方自己去映射错误信封。
     *
     * @param array<string, mixed> $data
     */
    public function hydrate(string $class, array $data): object
    {
        $validator = $this->validatorFactory->make($data, DTORuleFactory::from($class));
        if ($validator->fails()) {
            throw new ValidationException($validator, ResponseContext::getOrNull());
        }

        return DTOHydrator::instantiate($class, $validator->validated());
    }
}
