<?php

declare(strict_types=1);

use Hyperf\Apex\Http\Response\ApiResponseDTO;

/*
 * 响应信封配置。
 *
 * `response_dto` 必须指向应用的响应 DTO（实现 Hyperf\Apex\Http\Response\Contract\ApiResponseInterface）：
 * 组件不知道应用的业务字段（trace_id、时间戳、分页包装等），这一项只能由应用给出。
 *
 * 缺这一项时第一个请求会在构造 ApiResponseFactory 时抛错，而异常处理器自身也依赖它 ——
 * 那条错误连「转成业务信封」的机会都没有，最终只能返回裸 500。所以务必配上。
 */
return [
    // 应用有自己的响应 DTO 时换成它（需实现 ApiResponseInterface）。
    'response_dto' => ApiResponseDTO::class,

    /*
     * 额外的响应转换器（实现 ResponseTransformerInterface），按顺序排在组件自带的
     * 直通 / 信封转换器之前：用于把特定返回值换成另一种媒体类型。
     */
    'response_transformers' => [],
];
