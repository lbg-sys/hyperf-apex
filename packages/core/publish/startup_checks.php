<?php

declare(strict_types=1);

return [
    /*
     * 启动期 fail-fast 校验规则。
     *
     * EnvironmentConfigCheckListener 在服务监听端口前执行本文件里的声明，
     * 命中任意一条缺失即直接拒绝启动。新增必填环境变量、必填配置或自定义检查器，
     * 改本文件即可，不需要改监听器代码。
     */

    /*
     * 必填配置项：config 中 path 指向的值 trim 后为空即拒绝启动。
     *
     * - path：取值路径（config() 点语法）
     * - env：对应的环境变量名，用于错误提示；未声明时回退用 path
     * - hint：缺失原因与补充指引
     */
    'required' => [
        // 示例：
        // [
        //     'path' => 'app_name',
        //     'env' => 'APP_NAME',
        //     'hint' => '应用名称必须配置',
        // ],
    ],

    /*
     * 必须显式配置的环境变量：key 为变量名，value 为缺失说明。
     *
     * 检查的是原始环境变量而非合并后的 config 默认值，避免默认连接到 localhost 导致生产隐患：
     * `env('DB_HOST', 'localhost')` 这类写法在漏配时不会报错，只会安静地连到本机。
     *
     * 下面两条默认放行 —— 「本库底座默认会用到数据库与 Redis」是这份模板的假设，
     * 项目确实不用其中之一时，删掉对应条目即可；不要为了通过启动检查去填一个假值，
     * 那等于把检查关掉却留着检查的样子。
     */
    'required_envs' => [
        'DB_HOST' => '数据库主机地址',
        'REDIS_HOST' => 'Redis 主机地址',
    ],

    /*
     * 自定义启动检查器：实现 Hyperf\Apex\Diagnostics\Contract\CheckerInterface 的类名列表。
     *
     * 可在其中自定义复杂的依赖探测，例如存储驱动健康度、第三方 API 连通性、核心目录权限等。
     */
    'checkers' => [
        // App\Diagnostics\DatabaseConnectionChecker::class,
    ],
];
