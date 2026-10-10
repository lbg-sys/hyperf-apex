<?php

declare(strict_types=1);

return [
    /*
     * WebSocket 契约（Apifox 原生格式）的导出参数。
     *
     * WebSocket 不进 OpenAPI：OpenAPI 是「一次调用 = 一个 operation」的模型，表达不了
     * 「一次连接 + N 条双向消息」。契约改走 Apifox 原生格式的 webSocketCollection，
     * 由 `api-doc:generate-apifox` 生成，产物落在 `api_doc.output_path` 的 apifox.json，
     * 对外出口是 `GET /api_doc/get_apifox`。
     *
     * Apifox 侧接入方式（一次性配置）：项目设置 → 定时导入 → 数据源格式选「Apifox」→
     * URL 填 <接口地址>/api_doc/get_apifox。生成物顶层只含 apifoxProject / $schema /
     * webSocketCollection，不含 projectSetting 与 apiCollection，因此不可能覆盖现有 HTTP 接口。
     * 接口 id 由入口名派生，重复导入会匹配同一条；首次导入后请人工确认没有产生重复接口。
     *
     * 需要 `composer require hyperf-apex/apidoc-apifox` 才会安装本组件；
     * 只装了 hyperf-apex/apidoc 时该命令与本文档都不存在。
     */
    // webSocketCollection 下的目录名。
    'folder' => 'WebSocket',
    // 导入到 Apifox 后的接口状态：developing / testing / released / deprecated。
    'status' => 'developing',
    'tags' => ['WebSocket'],
];
