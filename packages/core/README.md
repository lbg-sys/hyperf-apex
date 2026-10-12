# hyperf-apex/core

Hyperf 3.2 + Swoole 6.2 的通用基础底座：请求 DTO 参数水合与校验、统一响应信封、声明式访问控制、
声明式限流与幂等、统一异常、日志通道、Fail-Fast 启动检查、仓储与分页基类。

- **依赖**：仅 Hyperf 基础组件（`hyperf/database`、`hyperf/di`、`hyperf/http-server`、`hyperf/validation` 等）
- **完整文档**：见主仓库 [hyperf-apex](https://github.com/lbg-sys/hyperf-apex)
- **变更记录**：见主仓库 [CHANGELOG.md](https://github.com/lbg-sys/hyperf-apex/blob/master/CHANGELOG.md)（本包已发布版本见 [Packagist](https://packagist.org/packages/hyperf-apex/core)）

## 安装

```bash
composer require hyperf-apex/core
```

## 发布配置

```bash
php bin/hyperf.php vendor:publish hyperf-apex/core --id=config
```

| 文件 | 用途 |
| :--- | :--- |
| `access_log.php` | 访问日志的排除名单（不跟随指标埋点） |
| `cors.php` | 跨域允许的来源与请求头 |
| `integration.php` | 集成调用凭证（`#[IntegrationAccess]` 校验用） |
| `request_exception_snapshot.php` | 未预期异常的请求快照（默认关） |
| `response.php` | 响应信封 DTO 与额外转换器（**必配**，见下） |
| `security.php` | 启动期访问声明检查开关 |
| `sql_log.php` | SQL 日志量级（全量 / 慢查询 / 写操作） |
| `startup_checks.php` | 启动期必填配置项与环境变量 |

## 配置项

| 键 | 环境变量（默认值） | 说明 |
| :--- | :--- | :--- |
| `access_log.exclude` | `ACCESS_LOG_EXCLUDE_PATHS`（`/metrics/*`） | 逗号分隔的路径通配；5xx 与异常请求照旧留痕 |
| `cors.allowed_origins` | `CORS_ALLOWED_ORIGINS`（`*`） | 允许的来源 |
| `cors.allowed_headers` | `CORS_ALLOWED_HEADERS`（空） | 额外的允许请求头 |
| `integration.integration_token` | `INTEGRATION_TOKEN`（空） | 集成凭证；**空值时中间件直接拒绝**，不会放行 |
| `request_exception_snapshot.enabled` | `REQUEST_EXCEPTION_SNAPSHOT_ENABLED`（`false`） | 是否给未预期异常留请求快照 |
| `request_exception_snapshot.whitelist` / `blacklist` | 同名环境变量 | 路径通配；先黑后白，白名单非空时只有命中才留痕 |
| `request_exception_snapshot.max_length` | `REQUEST_EXCEPTION_SNAPSHOT_MAX_LENGTH`（8192） | 快照正文上限（字节，按 `mb_strcut` 截断） |
| `response.response_dto` | — | 响应信封 DTO 的类名，必须实现 `ApiResponseInterface` |
| `response.response_transformers` | — | 额外的响应转换器 |
| `security.declaration_check.enabled` | `SECURITY_DECLARATION_CHECK`（`true`） | 端点访问声明检查；关掉时漏声明会静默通过 |
| `sql_log.full` | `SQL_LOG_FULL`（非生产默认 `true`） | 是否记录全量 SQL |
| `sql_log.slow_ms` | `SQL_LOG_SLOW_MS`（200） | 慢查询阈值（毫秒），`<= 0` 表示不按耗时记录 |
| `sql_log.writes` | `SQL_LOG_WRITES`（`true`） | 是否记录写操作 |
| `startup_checks.required` | — | 必填配置项规则数组（`path` / `env` / `hint`） |
| `startup_checks.required_envs` | — | 必填环境变量规则数组 |
| `startup_checks.checkers` | — | 自定义检查器（实现 `CheckerInterface`） |

## 主要能力与入口

| 能力 | 入口 |
| :--- | :--- |
| 请求 DTO 与参数水合 | `Hyperf\Apex\DTO\AbstractDTO` + `#[FromQuery]` / `#[FromBody]` / `#[FromMultipart]` |
| 字段约束（校验 + 文档同时生效） | `#[Length]`、`#[In]`、`#[ArrayOf]`、`#[MapOf]`、`#[EnumValue]` 等；只进文档的约束实现了 `DocumentationOnlyAttribute` |
| 统一响应信封 | `ApiResponseDTO`、`ApiResponseInterface`、`ApiResponseTransformer` |
| 访问声明与权限 | `#[PublicAccess]` / `#[IntegrationAccess]` / `#[ApiKeyAccess]` / `#[Authenticated]`、`#[RequirePermission]`、`#[RequireRole]`、`AccessDeclarationReader`、`Principal`、`PermissionSet` |
| 限流与幂等声明（实现在 reliability） | `#[RateLimit]`、`#[Idempotent]` |
| 统一异常 | `BusinessException`、`ErrorCode`、`AppExceptionHandler` |
| 日志通道 | `Hyperf\Apex\Logging\LoggerChannels::resolve()` |
| 启动检查 | `StartupChecker` + `CheckerInterface` |
| 数据访问 | `AbstractRepository`（含 `paginate()`）、`AbstractModel`（`create_time` / `update_time` / `delete_time`） |
| 时间 | `Hyperf\Apex\Support\Time::now()` |
| 命令 | `php bin/hyperf.php schema:model-check` |

## 用法

请求 DTO 与控制器（取值来源必须显式声明，漏标会在请求时报错而不是静默注入空 DTO）：

```php
final readonly class StoreUserRequestDTO extends AbstractDTO
{
    public function __construct(
        #[Length(min: 1, max: 32)]
        public string $name = '',
        #[FromQuery]
        public ?int $from = null,
    ) {
    }
}

#[Controller(prefix: '/users')]
final class UserController extends AbstractController
{
    #[Authenticated]
    #[RequirePermission('user:create')]
    #[PostMapping(path: '')]
    public function store(#[FromBody] StoreUserRequestDTO $request): ApiResponseDTO
    {
        return ApiResponseDTO::success(['id' => 1]);
    }
}
```

- 信封的 `code` 会映射成 HTTP 状态（`ErrorCode::getHttpStatus()`），未识别的自定义错误码保持原样。
- 每个接口方法都必须声明访问口径（方法级或类级），否则**应用启动失败**；方法级声明与类级口径会分层合并，
  权限码与角色取并集（合并方向永远不放松权限）。

## 需要自行实现的契约

| 契约 | 什么时候要 |
| :--- | :--- |
| `Hyperf\Apex\Http\Response\Contract\ApiResponseInterface` | 自定义响应信封 DTO（需实现 `code()`） |
| `Hyperf\Apex\Diagnostics\Contract\CheckerInterface` | 自定义启动检查项 |

## 边界

- 组件**不实现鉴权与认证本身**（那属于应用的身份体系）：它只负责「声明被正确推导、漏声明不会静默通过」，
  最终放行/拒绝由应用自己的中间件执行。
- 组件不做多租户、不预设权限码命名空间：权限码是不透明字符串，`guard` 原样透传。
