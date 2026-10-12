# hyperf-apex/reliability

高可用套件：Redis 分布式限流、接口幂等防重、事务发件箱（Outbox）、企微通知，以及统一的出站 HTTP 客户端。

- **依赖**：`hyperf-apex/core`、`hyperf/redis`、`hyperf/database`、`hyperf/guzzle`
- **可选集成**：`hyperf-apex/metrics`（装了才上报出站指标，见 `composer.json` 的 `suggest`）
- **声明侧在 core**：`#[RateLimit]` 与 `#[Idempotent]` 注解定义在 `hyperf-apex/core`（只依赖 core 的包可以直接引用注解），本包提供运行时实现
- **完整文档**：见主仓库 [hyperf-apex](https://github.com/lbg-sys/hyperf-apex)
- **变更记录**：见主仓库 [CHANGELOG.md](https://github.com/lbg-sys/hyperf-apex/blob/master/CHANGELOG.md)

## 安装

```bash
composer require hyperf-apex/reliability
php bin/hyperf.php vendor:publish hyperf-apex/reliability --id=config
php bin/hyperf.php vendor:publish hyperf-apex/reliability --id=migrations   # 幂等记录表 + 发件箱事件表
```

## 能力一览

| 能力 | 声明（在 core） | 运行时实现 | 配置段 |
| :--- | :--- | :--- | :--- |
| 分布式限流 | `#[RateLimit]` | `RateLimitMiddleware`、`RateLimiter` | `rate_limit.*` |
| 接口幂等 | `#[Idempotent]` | `IdempotencyMiddleware`、`IdempotencyService` | `idempotency.*` |
| 事务发件箱 | — | `OutboxPublisher`、`OutboxDeliveryService` | `outbox.*` |
| 通知 | — | `NotifyService` 与各渠道 | `notify.*` |
| 出站调用 | — | `OutboundHttpClient`、`OutboundRecorder` | 无（超时/TLS 在请求 DTO 上指定） |

## 限流

```php
#[RateLimit(capacity: 60, refill: 1)]
#[PostMapping(path: '')]
public function store(): ApiResponseDTO
```

| 参数 | 说明 |
| :--- | :--- |
| `name` | 规则名，留空时由注解位置派生，保证不同接口互不干扰 |
| `scope` | 限流维度（`RateLimitScopes`，默认按 IP），可自定义解析器 |
| `capacity` / `refill` | 桶容量（默认 60）与每秒补充量（默认 1） |
| `cost` | 单次请求消耗的令牌数 |

| 键 | 环境变量（默认值） | 说明 |
| :--- | :--- | :--- |
| `rate_limit.enabled` | `RATE_LIMIT_ENABLED`（`true`） | 总开关 |
| `rate_limit.redis_pool` | `RATE_LIMIT_REDIS_POOL`（`default`） | 计数所在的连接池 |
| `rate_limit.prefix` | `RATE_LIMIT_PREFIX`（`core:rl:`） | Redis 键前缀 |
| `rate_limit.resolvers` | — | 维度解析器 |
| `rate_limit.failure_mode` | `RATE_LIMIT_FAILURE_MODE`（`open`） | Redis 不可用时放行（`open`）还是拒绝（`close`）；**只接受这两个值**，写错在构造期抛错 |
| `rate_limit.ttl_jitter_ratio` | — | 过期时间抖动，避免同一时刻集中失效 |
| `rate_limit.whitelist_ips` | `RATE_LIMIT_WHITELIST_IPS`（空） | 完全豁免的来源（内网、监控），支持 CIDR |

被拒时返回 `429`，响应头取自**被拒的那条规则**：`X-RateLimit-Limit`、`X-RateLimit-Remaining`、
`Retry-After`（建议等待秒数）。

## 幂等

```php
#[Idempotent]
#[PostMapping(path: '')]
public function store(#[FromBody] StoreRequestDTO $request): ApiResponseDTO
```

- 请求头 `Idempotency-Key`：同一个键的重复请求不会重复执行，重放的那次响应带 `Idempotency-Replayed: true`。
- 记录按 `idempotency.retention_seconds` 保留（默认 7 天），到期的记录由清理任务分批删除。

| 键 | 环境变量（默认值） | 说明 |
| :--- | :--- | :--- |
| `idempotency.retention_seconds` | `IDEMPOTENCY_RETENTION_SECONDS`（604800，7 天） | 记录保留时长 |
| `idempotency.processing_timeout_seconds` | `IDEMPOTENCY_PROCESSING_TIMEOUT_SECONDS`（300） | 超过视为执行进程失联，后续同键请求可接管 |
| `idempotency.max_response_bytes` | `IDEMPOTENCY_MAX_RESPONSE_BYTES`（262144，256KB） | 响应体入库上限，`0` 表示不限；超限时只存状态码与响应类型（仍返回相同状态码、仍不重复执行） |
| `idempotency.cleanup_batch_size` / `cleanup_max_batches` | 同名环境变量（1000 / 1000） | 清理任务的分批参数 |

写回失败（连接池耗尽、主库切换、响应体超列长）**不会**把已经执行完的请求变成 500，只记 error。

## 事务发件箱（Outbox）

业务在事务里写事件，投递由消费进程或手工执行完成；投递前会先续租并校验租约，
租约已被别的 Worker 接管时跳过该条 —— 否则同一条事件的副作用会被执行两次。

| 键 | 环境变量（默认值） | 说明 |
| :--- | :--- | :--- |
| `outbox.enabled` | `OUTBOX_ENABLED`（`true`） | 是否启用 |
| `outbox.handlers` | — | 事件名 → 处理器类 |
| `outbox.max_attempts` / `event_max_attempts` | `OUTBOX_MAX_ATTEMPTS`（8） | 重试上限（全局与单事件） |
| `outbox.lock_seconds` | `OUTBOX_LOCK_SECONDS`（300） | 租约时长 |
| `outbox.backoff_base_seconds` / `backoff_max_seconds` / `backoff_jitter_ratio` | 同名环境变量（30 / 1800 / 0.2） | 退避参数 |
| `outbox.error_max_length` | `OUTBOX_ERROR_MAX_LENGTH`（4000） | 错误信息落库上限（按字节截断） |
| `outbox.process.enabled` | `OUTBOX_PROCESS_ENABLED`（`false`） | 是否随进程自动消费 |
| `outbox.process.poll_interval_seconds` / `batch_size` | 同名环境变量（2 / 50） | 消费节奏 |
| `outbox.retention_days` | `OUTBOX_RETENTION_DAYS`（`0`，不清理） | `outbox:prune` 清理已成功事件的窗口 |

```bash
php bin/hyperf.php outbox:prune   # 清理超出保留窗口的已成功事件
```

## 通知

`notify.channels` 声明启用的渠道，企微渠道的长度上限可配：

| 键 | 环境变量（默认值） |
| :--- | :--- |
| `notify.wecom.max_text_bytes` | `NOTIFY_WECOM_MAX_TEXT_BYTES`（2048） |
| `notify.wecom.max_markdown_bytes` | `NOTIFY_WECOM_MAX_MARKDOWN_BYTES`（4096） |
| `notify.wecom.max_image_bytes` | `NOTIFY_WECOM_MAX_IMAGE_BYTES`（2097152） |
| `notify.wecom.max_file_bytes` | `NOTIFY_WECOM_MAX_FILE_BYTES`（20971520） |

- 超限按 `mb_strcut`（字节）截断并附截断提示，不会把多字节字符切成半个。
- `@` 能力按 `msgtype` 自动降级：`markdown_v2` 不支持 `@` 时改用 `text`，`markdown` 带手机号或 `@all` 时同样降级。
- **没有** `notify.http.*` 配置段：超时与 TLS 校验由调用方投影成 `NotifyTargetDTO` 时指定，配一个不生效的开关只会误导运维。

## 出站 HTTP

`OutboundHttpClient` 统一出站调用：复用同一个 Guzzle 客户端（keep-alive 才真正生效）、
耗时按单调时钟统计、失败按传输层/状态码分档记录，装了 `hyperf-apex/metrics` 时会上报出站指标。

## 需要自行实现的契约

| 契约 | 说明 |
| :--- | :--- |
| `Hyperf\Apex\Outbox\Repository\Contract\OutboxEventRepositoryInterface` | 换存储（如 Redis）需实现全部方法，含投递前续租用的 `renewLease()` |
