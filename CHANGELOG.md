# 更新日志

本文件记录 `hyperf-apex/*` 各组件包的对外变更，格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [Unreleased]

本次为破坏性版本：清除从多端 SaaS 业务工程带入组件库的租户 / 代理商 / 系统端领域概念，组件只保留中性机制。
应用侧升级前请先读「破坏性变更」。

### 破坏性变更

- `Hyperf\Apex\Security\Principal` 移除 `$tenantId` / `$agentId` 两个业务维度，构造参数变为
  `(int $userId, string $userType, string $username, string $sessionId, int $permissionVersion = 1, array $claims = [])`。
  应用自己的数据范围、组织、渠道等维度改放进 `$claims`，组件不解释其内容。
- 删除 `#[RequireSystemPermission]` 与 `#[RequireAgentPermission]`：这两个注解把「系统端 / 代理端」固化进了组件，
  且无任何运行时消费者。统一改用 `#[RequirePermission]`；需要区分多套权限体系时用 `#[RequirePermission(guard: '...')]` 自行标注。
- `Hyperf\Apex\Security\AccessDeclaration` 移除 `$tenantCodes` / `$systemCodes` / `$agentCodes` 兼容字段与
  `permissionNamespace()`、`isContradictory()` 方法，权限码统一收敛到 `$permissions`。
- `Hyperf\Apex\Security\Contract\RoleAssignerInterface::assignBuiltinRole()` 移除 `?int $tenantId` 参数，
  签名变为 `(int $userId, string $userType): void`。
- `Hyperf\Apex\WebSocket\Registry\WsConnectionStore` 的连接表移除 `tenant_id` 列（此前只写入、从不读出）。
  共享内存表在启动时重建，重启进程即生效，不涉及数据库迁移。

## [2.0.0] - 2026-10-10

本次为破坏性版本：解除 `hyperf-apex/apidoc` 对 websocket / reliability 的硬依赖，并把限流、幂等中间件
从 `core` 迁回 `reliability`。应用侧升级前请先读「破坏性变更」与「应用侧需要手工同步」。

### 破坏性变更

- 限流与幂等中间件从 `hyperf-apex/core` 迁到 `hyperf-apex/reliability`，应用侧需同步
  `config/autoload/middlewares.php` 中的类名：
  - `Hyperf\Apex\Http\Middleware\RateLimitMiddleware` → `Hyperf\Apex\RateLimit\Middleware\RateLimitMiddleware`；
  - `Hyperf\Apex\Http\Middleware\IdempotencyMiddleware` → `Hyperf\Apex\Idempotency\Middleware\IdempotencyMiddleware`；
  - `IdempotencyMiddleware::HEADER` → `Idempotent::HEADER`（常量搬到契约类，取值仍是 `Idempotency-Key`）。
- 原因：这两个中间件直接使用 `RateLimiter`、`IdempotencyService` 等 reliability 运行时类，而
  `hyperf-apex/core` 按依赖方向约定不得依赖其它 Apex 包（依赖 reliability 会与后者成环）。此前 core 既未声明
  该依赖也未迁出实现，独立安装 `hyperf-apex/core` 的工程一旦启用这两个中间件就会「Class not found」；
  迁走后 core 对 reliability 再无任何引用。本次含破坏性变更，应随 `2.0.0` 发布。

### 新增

- 新增 `hyperf-apex/apidoc-apifox`：把 `#[WsGateway]` / `#[WsRoute]` 声明渲染成 Apifox 原生
  `webSocketCollection`，导出命令 `api-doc:generate-apifox`，对外出口 `GET /api_doc/get_apifox`。

### 变更

- `hyperf-apex/apidoc` 不再依赖 `hyperf-apex/websocket` 与 `hyperf-apex/reliability`，现在只依赖
  `hyperf-apex/core`、`swagger-php`、`symfony/yaml`。只做 HTTP 接口文档的工程不再被动安装长连接服务、
  Redis、数据库与 Guzzle，也不再被 `websocket` / `reliability` 的 `ConfigProvider` 注册启动监听器与命令。
  - Apifox（WebSocket 契约）导出整体迁到新包 `hyperf-apex/apidoc-apifox`；该命令的类名随之变为
    `Hyperf\Apex\ApiDoc\Apifox\Command\GenerateApifoxDocCommand`（命令不对外作为 API 使用）。
- 限流与幂等的声明式契约类迁入 `hyperf-apex/core`，**命名空间保持不变**，应用侧的 `use` 与注解无需改动：
  `#[RateLimit]`、`RateLimitScopes`、`RateLimitRule`、`RateLimitRuleReader`、`#[Idempotent]`。
  限流器、幂等记录仓储、Outbox 等运行时实现仍留在 `hyperf-apex/reliability`。

### 应用侧需要手工同步

- Apifox 导出配置从 `config/autoload/api_doc.php` 的 `apifox` 键迁到独立的 `config/autoload/apifox.php`：
  1. `composer require hyperf-apex/apidoc-apifox`；
  2. 执行 `php bin/hyperf.php vendor:publish hyperf-apex/apidoc-apifox --id=config`；
  3. 若曾自定义 `api_doc.apifox.folder` / `api_doc.apifox.status` / `api_doc.apifox.tags`，把自定义值
     手工搬进新的 `config/autoload/apifox.php`，再从 `api_doc.php` 中删除 `apifox` 段。
  未做第 3 步时导出会回落到默认值（`WebSocket` / `developing` / `['WebSocket']`）。

- 限流 / 幂等中间件换了命名空间：把 `config/autoload/middlewares.php` 里的旧类名换成新类名
  （对照见「破坏性变更」），并把用到 `IdempotencyMiddleware::HEADER` 的自有代码改成 `Idempotent::HEADER`。

- 维护者：`SPLIT_TOKEN` 现在需要对 6 个只读仓有写权限（新增 `lbg-sys/hyperf-apex-apidoc-apifox`）。

## [1.0.0] - 2026-10-10

### 新增

- 首次发布 5 个组件包与 1 个元包：
  - `hyperf-apex/core`：DTO 参数水合与约束校验、统一响应、声明式访问声明、统一异常与日志审计、Fail-Fast 启动检查；
  - `hyperf-apex/apidoc`：基于 DTO 反射的 OpenAPI 3.1 生成与 Apifox 格式导出；
  - `hyperf-apex/websocket`：长连接网关、跨 Worker 会话存储与 Swoole Table 连接表；
  - `hyperf-apex/reliability`：分布式限流、接口幂等防重、事务发件箱、企微多渠道通知、出站 HTTP 客户端；
  - `hyperf-apex/metrics`：基于 Swoole Table 共享内存的低基数指标与 `/metrics` 抓取端点；
  - `hyperf-apex/apex`：聚合全部组件的元包。
- 仓库工程化配置：`.gitattributes`（统一 LF 与 dist 瘦身）、子包拆分工作流（`Split`）、CI 质检工作流、
  PR 与 Issue 模板、Dependabot、`CONTRIBUTING.md`、`SECURITY.md`。

### 说明

- 已发布出去的迁移文件与 `publish/` 模板不得再修改或重命名，只能追加新迁移；修改模板默认值只影响
  之后重新发布的应用，需在本文件中写明应用侧需要手工同步哪一项。

[Unreleased]: https://github.com/lbg-sys/hyperf-apex/compare/v2.0.0...master
[2.0.0]: https://github.com/lbg-sys/hyperf-apex/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/lbg-sys/hyperf-apex/releases/tag/v1.0.0
