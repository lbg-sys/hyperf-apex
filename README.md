# Hyperf Apex

[![CI](https://github.com/lbg-sys/hyperf-apex/actions/workflows/ci.yml/badge.svg)](https://github.com/lbg-sys/hyperf-apex/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/hyperf-apex/core.svg)](https://packagist.org/packages/hyperf-apex/core)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.4-777bb3.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)

企业级 Hyperf 3.2 + Swoole 6.2 通用组件库（Monorepo 架构）。

本仓库采用组件化设计，既支持**按需引入单一功能包**（避免非必要依赖膨胀），也支持通过元包 `hyperf-apex/apex` **一键全量引入**。

---

## 模块清单与包规划（`packages/`）

| 组件包名 | 目录 | 职责说明 | 外部核心依赖 |
| :--- | :--- | :--- | :--- |
| **`hyperf-apex/core`** | `packages/core` | **通用基础底座**（DTO 参数水合、规则校验、统一响应、声明式权限/限流/幂等注解、统一异常、日志审计、Fail-Fast 启动检查） | 仅 Hyperf 基础组件，零外设冗余依赖 |
| **`hyperf-apex/apidoc`** | `packages/apidoc` | **OpenAPI 契约引擎**（基于 DTO 反射的无侵入 OpenAPI 3.1 生成） | `swagger-php`、`symfony/yaml` |
| **`hyperf-apex/apidoc-apifox`** | `packages/apidoc-apifox` | **长连接契约导出**（把 `#[WsGateway]` / `#[WsRoute]` 声明渲染成 Apifox 原生 `webSocketCollection`） | `hyperf-apex/apidoc`、`hyperf-apex/websocket` |
| **`hyperf-apex/websocket`**| `packages/websocket`| **长连接网关**（多端 WebSocket 协议封装、跨 Worker 会话存储、Swoole Table 连接表） | `hyperf/websocket-server` |
| **`hyperf-apex/reliability`**| `packages/reliability`| **高可用套件**（Redis 分布式限流与限流中间件、接口幂等防重与幂等中间件、事务发件箱 Outbox、企微多渠道通知、出站 HTTP 客户端） | `hyperf/redis`、`hyperf/database`、`hyperf/guzzle` |
| **`hyperf-apex/metrics`** | `packages/metrics` | **Prometheus 监控**（基于 Swoole Table 共享内存的低基数运行指标、请求耗时拦截与大屏） | `hyperf/memory`（Swoole Table） |
| **`hyperf-apex/apex`** | 根目录元包 | **全家桶元包（Metapackage）**（聚合依赖上述全部 6 个独立包，开箱即用） | 聚合所有子包 |

---

## 核心设计特性

- **纯通用权限体系**：提供统一顶层契约 `PermissionInterface`，消除对特定业务角色/枚举的硬编码；支持统一的 `#[RequirePermission]`（支持 `string`、`BackedEnum`、`PermissionInterface` 或其数组，支持 `guard` 自定义权限命名空间与 `mode` 组合）与 `#[RequireRole]` 注解；
- **插件化 Fail-Fast 启动检查**：`StartupChecker` 提供纯规则驱动配置（`required`、`required_envs`）与 `CheckerInterface` 自定义探测器插件机制，彻底剔除针对具体第三方驱动的写死逻辑；
- **强类型 DTO 架构**：基类统一收拢为 `Hyperf\Apex\DTO\AbstractDTO`，配合 `#[FromQuery]`、`#[FromBody]` 实现自动化参数水合、约束校验与 OpenAPI 3.1 规格自动推导；
- **现代化工程标准**：全库严格遵循 PHP 8.4 + Swoole 6.2，PHPStan Level 6 静态分析全量 0 error，100% PSR 规范排版。

---

## 接入方式

### 方式 1：按需引入（推荐，轻量无污染）

根据项目的实际业务形态，只安装所需的组件包：

```bash
# 纯 HTTP 接口服务 / 微服务（最精简底座，零冗余依赖）
composer require hyperf-apex/core

# 需要自动生成 OpenAPI 接口文档时（只依赖 core，不会牵入 websocket / reliability）
composer require hyperf-apex/apidoc

# 需要把 WebSocket 契约同步到 Apifox 时（在 apidoc 之上追加，会一并引入 websocket）
composer require hyperf-apex/apidoc-apifox

# 需要 WebSocket 长连接网关与会话管理时
composer require hyperf-apex/websocket

# 需要分布式限流、幂等防重、事务发件箱与通知告警时
composer require hyperf-apex/reliability

# 需要 Prometheus 性能监控指标时（自带 /metrics 抓取端点与 /metrics/dashboard 运维大屏）
composer require hyperf-apex/metrics
```

### 方式 2：全量引入（全家桶）

```bash
composer require hyperf-apex/apex
```

---

## 配置文件发布（vendor:publish）

每个包均提供独立的配置发布 ID：

```bash
# 发布基础底座配置（access_log, integration, sql_log, startup_checks 等）
php bin/hyperf.php vendor:publish hyperf-apex/core --id=config

# 发布 ApiDoc 配置
php bin/hyperf.php vendor:publish hyperf-apex/apidoc --id=config

# 发布 Apifox 长连接契约导出配置（需先 composer require hyperf-apex/apidoc-apifox）
php bin/hyperf.php vendor:publish hyperf-apex/apidoc-apifox --id=config

# 发布 WebSocket 配置
php bin/hyperf.php vendor:publish hyperf-apex/websocket --id=config

# 发布可靠性套件配置与数据库迁移模板（幂等记录表、发件箱事件表）
php bin/hyperf.php vendor:publish hyperf-apex/reliability --id=config
php bin/hyperf.php vendor:publish hyperf-apex/reliability --id=migrations

# 发布 Prometheus 监控配置
php bin/hyperf.php vendor:publish hyperf-apex/metrics --id=config
```

---

## 日志通道

组件按用途分流日志，会优先使用下面这些通道名（Hyperf 的 `logger.channels` 配置）：

| 通道 | 用途 |
| :--- | :--- |
| `error` | 异常与错误：`AppExceptionHandler`、请求异常快照、Outbox 投递、WebSocket |
| `shared` | 限流：`RateLimiter`、`RateLimitMiddleware` |
| `outbound` | 第三方出站调用：`OutboundHttpClient` |
| `sql` | SQL 日志：`sql_log.enabled=true` 时的 `DbQueryExecutedListener` |
| `access` | 访问日志：`RequestTimingMiddleware`（与 `access_log` 配置配套） |

这些通道名不在 Hyperf 骨架的默认配置里，所以有两件事要知道：

- **没配置不会报错**。组件统一经 `Hyperf\Apex\Logging\LoggerChannels::resolve()` 取通道，
  通道不存在就退回 `default`（日志混在一起，功能完整）。直接调 `LoggerFactory::get($name, $channel)`
  取通道会在通道缺失时抛异常且发生在构造函数里，所以组件源码里不要这么写。
- **想分流就自己定义同名通道**，例如把访问日志单独落一个文件：

```php
// config/autoload/logging.php
'channels' => [
    'default' => [/* Hyperf 骨架自带 */],
    'access' => [
        'handler' => [
            'class' => Monolog\Handler\StreamHandler::class,
            'constructor' => [
                'stream' => BASE_PATH . '/runtime/logs/access.log',
                'level' => Monolog\Level::Info,
            ],
        ],
        'formatter' => [
            'class' => Monolog\Formatter\LineFormatter::class,
            'constructor' => [],
        ],
    ],
],
```

---

## WebSocket 网关（`hyperf-apex/websocket`）

### 1. 运行前置（启动期会校验，不满足直接起不来）

| 配置项 | 为什么 |
| :--- | :--- |
| `server.type` + `server.settings.worker_num` | 协程服务器（Hyperf 3.1+ 默认）下 `Sender` 只能在当前 worker 内投递，`worker_num` 因此必须为 `1`；确实只收不发时用 `websocket.allow_multi_worker=true` 显式接受限制。传统 `Hyperf\Server\Server` 会广播 PipeMessage，不受此限 |
| `server.settings.package_max_length` | 必须 ≥ `websocket.max_frame_bytes`，否则超限帧在协议层就被 Swoole 断开，客户端拿到的是断连而不是错误信封 |
| `websocket.idle_timeout` | 空闲连接回收时限（默认 180 秒），不能与 Swoole 的 `heartbeat_idle_time` 同时为 0：连接索引是定长表，写满后新连接会被直接拒绝且不会自愈 |

### 2. 声明入口与事件

```php
#[WsGateway(path: '/ws/hub', server: 'ws', endpoint: 'hub', name: '主连接', middlewares: [WsIntegrationHandshakeMiddleware::class])]
final class HubWsGatewayController extends WsGatewayController
{
}

final class HubEventHandler
{
    #[WsRoute('demo.created')]
    public function handle(DemoCreatedPayloadDTO $payload, WsRequestContext $context): DemoCreatedResultDTO
    {
        return new DemoCreatedResultDTO($payload->recordId, $context->connectionId());
    }
}
```

- 入口类必须实现 `OnOpenInterface` / `OnMessageInterface` / `OnCloseInterface`（继承 `WsGatewayController` 即可，子类只负责带注解）。
- 处理器签名由启动期反射校验：一个 payload DTO（`AbstractDTO` 子类）+ 可选的 `WsRequestContext`，返回 `void` 或响应 DTO。
- 事件名格式为 `{domain}.{action}`（全小写、点号分隔），`sys.` 前缀为内置事件保留。

### 3. 鉴权

- **集成凭证**：入口中间件挂 `WsIntegrationHandshakeMiddleware`，token 走 query 参数 `token`（浏览器 WebSocket API 无法自定义请求头）。**token 会因此出现在网关与访问日志里**，部署时应在网关层屏蔽该参数的记录。
- **用户身份**：组件不内置登录态鉴权（它属于应用的身份体系）。应用自建握手中间件，校验通过后调用 `WsHandshakeContext::setPrincipal($principal)` 交出身份，入口会在 `onOpen` 时把它组装进会话；token 解析可复用 `WsBearerToken::token()`。
- 校验失败必须发生在 `onOpen` 之前（中间件里抛 `BusinessException` 即可），否则会出现「连接已建立但没有身份」的中间态。

### 4. 推送

业务侧只依赖 `WsPusherInterface`：`pushToUser()` / `pushToRoom()` / `pushToEndpoint()` / `broadcast()`。
**不承诺可靠投递** —— 连接断开期间的推送即丢失，需要可靠性的业务走 HTTP 拉取。

### 5. 协议

| 方向 | 报文 |
| :--- | :--- |
| 客户端 → 服务端 | `{"id": "1", "event": "demo.created", "data": {...}}`，`data` 必须是 JSON 对象 |
| 服务端 → 客户端（响应） | `{"id": "1", "event": "demo.created", "code": 0, "message": "成功", "data": {...}}` |
| 服务端 → 客户端（推送） | `{"id": "...", "event": "demo.created", "type": "push", "data": {...}}` |

内置事件：`sys.ping`（心跳，客户端定期发）、`sys.pong`、`sys.subscribe` / `sys.unsubscribe`（房间订阅，`data.room` 为 1-64 位 `[A-Za-z0-9_.:-]`）。

约定：

- **任何业务错误都只回错误信封，不断开连接**（含协议错误、限流、未注册事件、业务拒绝），客户端的重连策略只用于处理真正的网络断开。
- 单连接消息限流默认「1 秒 50 条」，超限回 `10007`（`TOO_MANY_REQUESTS`）；用 `websocket.rate_limit.max_messages=0` 关闭。
- 客户端必须在 `websocket.idle_timeout` 内至少发送一条消息（通常就是 `sys.ping`），否则连接会被服务端回收。

---

## 开发与质检规范

- **运行环境**：PHP 8.4 + Swoole 6.2，统一使用 `swoole62` 与 `composer62` 命令；
- **单元测试**：`composer62 test`（PHPUnit 11）；
- **静态分析**：`composer62 analyse`（PHPStan Level 6，全包 0 error）；
- **代码格式化**：`composer62 cs-fix`（PHP-CS-Fixer）；
- **参与开发**：见 [CONTRIBUTING.md](CONTRIBUTING.md)（环境、门禁、分支模型、发布流程）；
- **变更记录**：见 [CHANGELOG.md](CHANGELOG.md)。

---

## 关于本仓库的发布方式

本仓库是 Monorepo，对外的 6 个包由两条路径发布：

| Composer 包名 | 发布来源 |
| :--- | :--- |
| `hyperf-apex/core`、`apidoc`、`apidoc-apifox`、`websocket`、`reliability`、`metrics` | 在 `master` 打 `vX.Y.Z` tag 后，由 `.github/workflows/split.yml` 拆分并推送到 `lbg-sys/hyperf-apex-{包名}` 只读仓，Packagist 从只读仓索引版本 |
| `hyperf-apex/apex`（元包） | 直接由本仓库 `lbg-sys/hyperf-apex` 发布；`packages/`、`test/` 等已标记 `export-ignore`，dist 包只含 `composer.json` 与 `LICENSE` |

发布流程：更新 `CHANGELOG.md` → 在 `master` 上打 `git tag -a vX.Y.Z -m "chore(release): vX.Y.Z"` → `git push origin vX.Y.Z`。
