<?php

declare(strict_types=1);

use function Hyperf\Support\env;

/*
 * 安全声明相关配置。
 */
return [
    /*
     * 启动期校验每个接口端点都显式声明了访问口径。
     *
     * 组件不实现鉴权（那属于应用），但「有没有声明」必须有人守：漏标口径的方法既不会被
     * 任何运行时检查拦下，文档侧又会把它描述成公开接口（`security` 被省略）—— 方向恰好
     * 是最危险的那个，且没有任何迹象。
     *
     * 迁移期可以临时关掉，但关掉的这段时间里漏声明会静默通过。
     */
    'declaration_check' => [
        'enabled' => (bool) env('SECURITY_DECLARATION_CHECK', true),
    ],
];
