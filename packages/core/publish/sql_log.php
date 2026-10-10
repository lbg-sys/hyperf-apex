<?php

declare(strict_types=1);

use function Hyperf\Support\env;

/*
 * SQL 日志量级控制。
 *
 * 全量 SQL 日志按「每条查询一行」增长，单日可达 GB 级，而其中绝大多数行永远不会被看 ——
 * 按请求数 × 每请求 SQL 条数估算即可确认，单靠切文件解决不了这个量级。
 *
 * 生产只留「写操作 + 慢查询」，排查所必需的信息一条不少：
 * - 报错的 SQL 不走这里。查询失败时 QueryExecuted 根本不派发（Connection::run 里 logQuery
 *   在 handleQueryException rethrow 之后），但 QueryException 自带完整 SQL 与 bindings，
 *   会作为未预期异常进 error 日志。
 * - 慢查询通常就是 bug 现场。
 * - 写操作保留下来，用于追溯数据是被什么改动过。
 *
 * 本地与测试环境保持全量，方便直接看每条 SQL。
 */
$production = env('APP_ENV', 'dev') === 'prod';

return [
    /*
     * 全量记录。非生产默认开，生产默认关。
     *
     * 显式覆盖时不要写成空值：env() 对「有 key 但值为空」返回空串，(bool) '' 是 false。
     */
    'full' => (bool) env('SQL_LOG_FULL', ! $production),

    /*
     * 慢查询阈值（毫秒）。<= 0 表示不按耗时记录。
     *
     * 用时单位就是毫秒：Connection::getElapsedTime 返回 ms，日志里的 [17.04] 即 17.04ms。
     */
    'slow_ms' => (float) env('SQL_LOG_SLOW_MS', 200),

    /*
     * 是否记录写操作（INSERT / UPDATE / DELETE / REPLACE）。
     */
    'writes' => (bool) env('SQL_LOG_WRITES', true),
];
