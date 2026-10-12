# 更新日志

本文件记录 `hyperf-apex/*` 各组件包的对外变更，格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [Unreleased]

### 破坏性变更

- `Hyperf\Apex\WebSocket\Contract\WsConnectionStoreInterface` 新增 `hasConnection()`、`touch()`、
  `idleConnections()`：分别用于推送时剔除失效的房间成员、刷新连接活跃时间、扫描空闲连接。
  自行替换连接索引实现（例如改用 Redis）的工程需要补齐这三个方法。
- 构造签名新增依赖（均由容器自动装配，只有手工 `new` 的场景需要跟改）：
  `WsPushService` 与 `WsGatewayRegisterListener` 新增 `LoggerFactory`；
  `WsDispatcher` 与 `WsGatewayController` 新增 `WsConnectionRateLimiter`；
  `IdempotencyMiddleware` 新增 `ConfigInterface`（读 `idempotency.max_response_bytes`）。
- `Hyperf\Apex\Outbox\Repository\Contract\OutboxEventRepositoryInterface` 新增 `renewLease()`：
  投递前续租并校验租约。自行实现该接口（例如换存储）的工程需要补齐。
- `api-doc:generate` **新增三项生成期校验**，此前这些写法都会被静默接受并产出错误的文档：
  接口方法漏写 `#[ApiOperation]`（曾退回英文方法名当 summary）、`#[ApiError]` 里声明 `SUCCESS`
  （曾产出 `code` 枚举为空的 200 响应）、`#[ApiHeader]` 声明认证头（曾与 `securityScheme` 重复声明）。
  存量工程升级后需要补齐这三类声明，否则生成命令会失败。
- **新增启动期访问声明检查**（`PermissionDeclarationCheckListener`）：每个带路由注解的公开方法
  都必须能解析出访问口径（`#[PublicAccess]` / `#[IntegrationAccess]` / `#[ApiKeyAccess]` /
  `#[Authenticated]` 之一，方法级或类级皆可），且不能同时声明多个口径，否则**应用启动失败**。
  存量工程需先补齐声明；迁移期可用 `security.declaration_check.enabled=false` 临时关闭
  （关掉的这段时间里漏声明会静默通过）。
- `Hyperf\Apex\Security\AccessDeclaration` 构造新增 `permissionMode` / `roleMode` 参数，
  并新增 `hasAccessDeclaration()` / `declaredAccessModes()` / `mergedWith()`；
  `#[RequireRole]` 的空角色标识改为构造期抛错（与 `#[RequirePermission]` 同一判据）。

### 修复

- **推送不再因 `Sender::push()` 返回 false 就摘除连接索引**：协程服务器（Hyperf 3.1+ 默认）下该返回值
  同时表示「fd 不属于本 worker」，误摘会让一条在线连接彻底收不到推送，且客户端毫无感知。
  索引清理统一交给 `onClose` 与空闲回收。
- `websocket.enabled=false` 时不再执行 WebSocket 声明检查：此前「关掉网关但保留入口代码」会因为
  「没有任何 `#[WsGateway]` 入口」而启动失败，与开关语义矛盾。
- `WsDeclarationCheckListener` 提前到 `WsGatewayRegisterListener` 之前执行：注册器内部的扫描失败会
  直接抛异常，导致「声明与处理器错误一次性汇总」的机制永远执行不到，声明错误只能一轮一轮地改。
- **组件一律经 `LoggerChannels::resolve()` 取日志通道**（14 处）：`LoggerFactory::get($name, $channel)`
  在通道未定义时直接抛 `InvalidConfigException`，而组件惯用的 `error` / `shared` / `outbound` / `sql` /
  `access` 通道 Hyperf 骨架并不提供 —— 应用没配就是「装完即崩」，报错还发生在组件的构造函数里。
  现在配了专用通道就用它，没配自动退回 `default`，不再因为少一个通道而启动/请求失败。
- 限流被拒时的响应头改为取自**被拒的那条规则**（此前按「剩余额度最小」挑，可能挑到一条放行规则，
  于是 429 响应不带 `Retry-After`，`X-RateLimit-*` 描述的也是另一条规则的额度）。
- 限流维度解析失败（注解 scope 未注册、主体标识取不到）不再每个请求写一条 warning：
  同一处配置错误会让日志盘被写满、真故障被淹没，现在按 scope 只告警一次。
- 幂等记录写回抛异常（连接池耗尽、主库切换、响应体超列长）不再把已经执行完的请求变成 500，
  只记一条 error；记录滞留 `processing` 由 `processing_timeout_seconds` 后的接管流程兜住。
- `rate_limit.failure_mode` 只接受 `open` / `close`，非法值在构造期直接抛错：此前「不等于 close
  就当成 open」，配成 `Close` 之类的写法会让限流保护在 Redis 故障时静默失效。
- 删除 `notify.http.*` 配置段：全仓库没有任何读取点（超时与 TLS 校验由调用方投影成
  `NotifyTargetDTO` 时指定），留着一个配了不生效的开关只会误导运维。
- **Outbox 执行 handler 之前先续租并校验租约**：整批事件共用认领时写下的那一个租约，而批内是
  逐条串行执行的 —— 处理到靠后的条目时原租约可能已过期、事件已被别的 worker 重新认领，
  此时继续执行会让同一条事件的副作用发生两次（fencing 只保证写回只有一份，拦不住副作用）。
  续租失败即跳过该条。
- Outbox 的 `outbox.error_max_length` 改为按**字节**截断（列是 text，按字符数算中文内容会超），
  与 `WecomRobotChannel` 的截断口径一致。
- Outbox 未注册 handler 的日志级别从 error 降为 warning：发布窗口里消费进程先起来属于预期状态，
  用 error 会把发布期日志刷满，真正的错误反而被淹没。
- 幂等记录新增 `idempotency.max_response_bytes`（默认 256KB）：超限时不入库响应体，
  只保留状态码与响应类型 —— 记录要保留 7 天，不设上限会让一次导出接口长期占库，
  超过列长（mediumText）还会让写回直接失败。重复请求仍返回相同状态码且不重复执行。
- 出站 HTTP 的计时统一走 `hrtime`（此前 4 处 `microtime` 与 `OutboundRecorder::secondsSince()`
  两套口径，墙上时钟回拨时会出现负耗时），并复用同一个 Guzzle 客户端（此前每次请求都新建，
  等于放弃 keep-alive）。
- 企微渠道的 `@` 能力矩阵改用 `NotifyMessageFormat::supportsMentionList()` /
  `supportsInlineMention()`：此前渠道里硬编码了一遍同样的矩阵，两个方法成了死代码，
  改一处漏一处不会报错、只会静默发成错误的 msgtype。
- **请求 DTO 参数漏标取值来源时不再被静默吞掉**：`#[FromQuery]` / `#[FromBody]` /
  `#[FromMultipart]` 都没标时，此前会落到容器注入 —— 而 Hyperf 的容器对任何可实例化的类
  都能建出定义，于是「成功地」造了一个空 DTO（请求体被忽略），或抛一条看不懂的容器异常。
  现在直接点名报「必须用 …… 指明取值来源」。
- `#[FromQuery('name')]` / `#[FromBody('name')]` 的 `name` 对 **DTO 参数**同样生效：
  此前只在标量分支使用，DTO 分支静默拿整个 query / body，声明与行为不符。
- 可空的标量 `#[FromQuery]` / `#[FromBody]` 参数拿到空值得出 null（`?page=` 这类形状），
  不再 400 —— 口径与 DTO 路径（`RequestDataHydrator::castBuiltin`）一致，
  此前同一个「空值」在两种参数形态下一个是 null、一个是参数错误。
- `StartupChecker` 不再把布尔 `false` 与数字 `0` 判成「未配置」：判定曾用
  `trim((string) $value) !== ''`，而 `(string) false === ''` —— 于是合法的 false 会让服务
  拒绝启动，报的还是「某某项未配置」。
- `#[RequirePermission]` 的空权限码在构造期直接抛错：空码会让 `requiresPermission()` 为真
  却无人能持有它，表现是「该接口对所有请求恒拒绝」且看不出原因。
- **5xx 的 `HttpException` 不再算「预期内的失败」**（`ServerErrorHttpException`、
  `new HttpException(500, …)`）：此前它与 4xx 一样被直接转成响应，既不写 error 日志、
  也不触发未捕获异常告警，真实的服务端故障只剩 access 日志这一条线索。
  现在它走未捕获路径（记日志 + 告警，客户端仍拿到 500）。
- **文档出口与生成命令的产物路径统一**（新增 `Hyperf\Apex\ApiDoc\Support\ApiDocPaths`）：
  `GET /api_doc/get_openapi` 与 `get_apifox` 此前把路径写死成 `BASE_PATH/runtime/api-doc`，
  应用按 `api_doc.output_path` 把产物挪走后，生成命令报成功、接口却一直返回「尚未生成」，
  两者之间没有任何线索。现在生成命令（OpenAPI / Apifox）与出口共用同一份解析。
- 只声明 `#[RequireRole]` 的接口不再被文档描述成公开可调：角色要求隐含「已登录」，
  因此同样输出 `security` 并补 403，角色需求以 `x-required-roles` 暴露。
  此前 `SecurityResolver` 与 403 判据都只看权限码，角色声明被完全忽略。
- 只有请求头（`#[ApiHeader]` / `#[Idempotent]`）的接口不再多补一个 422：
  422 只来自参数水合与校验，请求头不参与其中，补上等于宣称一条不可达的失败路径。
- 散装数组请求参数（`#[FromQuery] array $codes`）在生成期直接报错并要求改用查询 DTO：
  `#[ArrayOf]` 只能标在 DTO 属性或方法上，裸 `array` 参数只能产出没有 `items` 的
  `type: array` —— 上游把它判为 warning，会让整个生成命令失败，报的还是一条与业务代码
  无关的编译器提示。
- `@return` 注释的类型提取改为按尖括号配对扫描，剥掉尾随的中文说明：此前 `FooDTO[] 列表`
  会退化成「类名是 `FooDTO[] 列表`」的叶子，`array<string, FooDTO> 映射` 因为结尾不是 `>`
  解析失败、回落成没有 `items` 的裸 array。
- **自引用 DTO 会让首个请求栈溢出**（`DTORuleFactory`）：`#[ArrayOf(SelfDTO::class)]` 这类自引用
  （以及 A↔B 互相引用）此前会让校验规则的键每层变长、永不收敛 —— 展开发生在请求热路径上，
  第一个请求就栈溢出或把内存撑爆。现在「同一个类又出现在当前展开路径上」即停，
  只保留「元素必须是对象」这一条，与文档用 `$ref` 表达的口径一致。
- `#[MapOf('X')]` 的值类型此前完全不参与校验：`DTOMetadataFactory` 只识别 `#[ArrayOf]`，
  于是文档写了值类型、服务端却照单全收（`#[MapOf('int')]` 传字符串也放行）。现在映射值与
  数组元素走同一条规则生成路径（`字段.*` 通配对字符串键同样成立）。
- `mixed` / `object` 属性的校验规则不再要求「必须是数组」：它们此前落到 `default => ['array']`，
  与文档（无约束）和水合（原样透传）三层打架 —— 响应与请求复用同一个 DTO 时必然误报 422。
- `#[ArrayOf('短类名')]` 在生成期直接报错：字符串里的类名不经过 `use` 解析，
  此前会静默落成 `items: {type: string}`，而校验按对象展开、水合按对象转型。
- 「多个 Attribute 抢同一个 Schema 关键字」的判定改为比较**值**：此前只看关键字是否出现过，
  `#[MapOf]` + `#[PropertyCount]` 这种都声明 `type: object` 的合法组合也会被拦下；
  报错信息现在还会点出是哪两个 Attribute 在抢。同时 `#[ArrayOf]` 的元素片段也过
  「关键字与 type 是否自洽」的收口 —— `#[ArrayOf('string')]` + `#[ItemMinimum(1)]` 会产出
  不会被任何校验器执行的死约束，此前只有属性级写法会报错。
- 泛型 DTO 的组件名净化：元素类型写成类型表达式时（`#[ArrayOf('list<int>', property: 'list')]`）
  组件名会带 `<`、`>` 等字符，而它要写进 `#/components/schemas/…` 引用里，
  多数 OpenAPI 工具与代码生成器解析不了。
- `EnumValue` 与 `ReturnTypeResolver` 的四处英文报错信息改为中文，并带上方法 / 属性定位。
- **信封里的业务状态码现在会反映到 HTTP 状态码上**（`ApiResponseTransformer`）：控制器直接返回
  信封时（`ApiResponseDTO::make(ErrorCode::RESOURCE_NOT_FOUND)`）此前一律是 200 —— 响应体说
  「资源不存在」、HTTP 说「成功」，而文档（按 `ErrorCode::getHttpStatus()` 生成）、监控的错误率
  与客户端的重试策略全都按 HTTP 状态工作。认不出的自定义错误码不猜状态，保持原样。
- `AbstractRepository::insertRowsOrIgnore()` 三处修正：数组并集写反，导致调用方显式给出的
  `update_time` 被静默覆盖（注释承诺的是相反的优先级）；`create_time` 此前依赖列的
  `CURRENT_TIMESTAMP` 默认值，会让同一张表出现两个时钟（`create_time` 来自数据库、
  `update_time` 来自应用服务器），与「时间值一律由应用侧传入」的约定冲突；返回值改为
  **累计受影响行数** —— `INSERT IGNORE` 会把数据类错误（非法值、超长截断、NOT NULL 违规）
  也降级成警告并丢行，不只是唯一键冲突，调用方据此才能发现「少插了行」。
- `ResponseEncoderResolver` 的类级 `#[ResponseEncoder]` 改为沿继承链查找：此前只看方法的
  声明类，控制器把动作放在抽象基类里（注解在子类上）或子类覆写父类方法（注解在父类上）时，
  声明会静默失效 —— 接口返回 JSON，而文档按 `contentType()` 生成另一种媒体类型。
- `AbstractModel` 补上 `public const DELETED_AT = 'delete_time'`：Hyperf 的 `SoftDeletes` 默认用
  `deleted_at`，而本仓库约定（以及 `schema:model-check` 的校验规则）是 `delete_time`，
  模型只 `use SoftDeletes` 而不写常量会去碰一个不存在的列，要等运行期才炸。
- `ApiResponseDTO::success()` 的 `data` 默认值从 `[]` 改为 `null`：无业务数据的接口在文档里是
  `data: null`，给 `[]` 会让「没有数据」与「数据是空列表」在响应里长得一模一样。
- `ModelMetadataReader` 不再把模型未指定连接时的连接名硬编码成 `'default'`：应用的默认连接常叫
  `mysql` / `main`，写死会让 `schema:model-check` 去解析一个不存在的连接（或报「连接 default
  不是连接池连接」）。改为取连接解析器的默认连接，与框架解析时同一个来源。
- `SchemaModelChecker` 识别 `?int` 这类可空简写：此前它被当成关系属性（如 `null|Message`）
  而跳过「注解与列是否对得上」的检查，静默漏报。
- `JsonResponseEncoder` 加 `JSON_INVALID_UTF8_SUBSTITUTE`：库里可能存着非 UTF-8 字节
  （历史数据、从上游原样回放的响应体），严格模式下一条脏数据就把整条成功响应升级成 500。
- `AbstractModel::getEventDispatcher()` 缓存解析结果：retrieved 事件在每次模型水合时都会问一次
  （连只读的 `find()` 也算），而容器在运行期不变。不缓存 `null` —— worker 启动完成前那一刻的
  结果会把此后所有 Model 事件按死。
- **方法级的细粒度要求不再顶掉类级的访问口径**：此前只要方法级出现 `#[RequirePermission]` /
  `#[RequireRole]`，整个类级声明（含 `#[Authenticated]`）就被丢弃 —— 依赖 `authenticated`
  决定是否要求登录的消费者会直接放行。现在两个维度分开合并：口径方法级优先、回退类级；
  权限码与角色取并集（合并方向永远不放松权限）。
- **类级访问声明改为沿继承链查找**：类上的 Attribute 不被继承，动作写在子类、口径声明在
  抽象基类上时，此前会「查不到任何声明」（与 `ResponseEncoderResolver` 修过的是同一类缺陷）。
- `#[RequirePermission]` / `#[RequireRole]` 的 `mode`（AND / OR）此前是**死参数**：
  声明里写了 OR，而 `AccessDeclaration` 没有这个字段、判定处也拿不到，最终按 AND 执行
  （静默收紧，权限要求比声明更严）。现在透传给应用判定。
- `PermissionSet::codes()` 保证返回字符串：形如十进制整数的权限码会被 PHP 规整成 int 键，
  混进 `list<string>` 会让严格比较的下游踩坑。
- 权限码与角色的守卫标识拆成两个字段（`AccessDeclaration::$guard` / `$roleGuard`）：
  此前共用一个字段，`#[RequirePermission(guard: 'admin')]` 与 `#[RequireRole(guard: 'ops')]`
  同时出现时后写的会把先写的顶掉，「admin 体系要权限码 + ops 体系要角色」这类声明会丢一半。
- `EnumTrait` / `PermissionCodeTrait` 的四处英文异常信息改为中文（`Enum message is not configured
  for X::Y` 之类），并保留 `类::case` 定位 —— 与「面向使用者的异常必须中文」的约定一致。
- **SQL 日志脱敏会漏遮**（`SqlBindingRedactor`，两处）：① 占位符按 `strpos` 顺序找，字面量里的
  `?`（如 `WHERE note LIKE '%?%'`）被当成了占位符 —— 后续绑定整体错位，敏感值判定也跟着错位，
  本该遮住的 `password_hash` 以明文落进日志；现在按引号状态扫描真正的占位符（含反斜杠转义）。
  ② 展开的绑定值不做转义：`O'Brien` 输出成 `'O'Brien'`（日志失真），含换行的值能在日志里
  伪造出完整的假行（log injection）；现在按 SQL 字面量规则转义，且 `null` / 布尔 / 数字不再
  套引号（`id = '5'` 会让人误以为那是字符串列）。
- **包间约束与 `branch-alias` 停在 2.x（发布事故级）**：`v3.0.0` 已打 tag，但根元包与 5 个组件包的
  `hyperf-apex/*` 约束仍是 `^2.0`（即 `>=2.0.0 <3.0.0`）、`branch-alias` 仍是 `2.0.x-dev` ——
  装 `hyperf-apex/apidoc:^3.0` 会去解析 `core:^2.0`，**把 3.x 的包与 2.x 的 core 混装**，
  而 3.x 的代码依赖 3.x 的 core API（新类、新方法），结果是装不上或运行期报错。
  现已统一提升到 `^3.0` / `3.0.x-dev`，并同步 `composer.lock`、`split.yml` 与 Issue 模板里的
  版本示例（`v2.0.0` → `v3.0.0`）。已发布的 3.0.0 无法撤回：使用它的工程需显式要求
  `hyperf-apex/*:^3.0`，或等下一个补丁版本。
- `SensitiveDataFilter` 的敏感键名补上 `api_key` / `apikey` / `private_key` / `signature`：
  这些字段名不会命中原有的 `password` / `token` / `secret` 等词，但落进审计或异常快照就是泄漏。
  裸 `key` 刻意不加 —— 它作为普通字段名太常见（`cache_key`、`sort_key`），加进来会遮掉大量正常字段。
- **联合类型属性此前完全没有类型规则**（`int|string` 这类）：类型不符的值（`{"value": {}}`）
  能通过校验，直到构造器赋值时才抛 TypeError —— 客户端拿到 500 而不是 422，而文档侧本来就用
  `oneOf` 描述这些字段。新增 `Hyperf\Apex\Validation\Rule\UnionTypeRule`（「满足任一分支即可」，
  判定口径与单一类型一致：`integer` 认数字字符串、布尔不算整数），`null` 分支仍由 nullable 规则承担；
  有分支本身没有类型约束（`object`、未知类名）时整个联合没有可判定的边界，维持不加规则。
- **元素层（`items`）的死约束检查此前形同虚设**：`TYPE_BOUND_KEYWORDS` 只列了字符串 / 数组 / 对象
  三组关键字，`minimum` 这类数值关键字根本不在表里，于是 `#[ArrayOf('string')] #[ItemMinimum(1)]`
  仍然产出 `items: {type: string, minimum: 1}` 这种不会被任何校验器执行的约束。关键字表改为
  「关键字 → 生效类型集合」（数值关键字对 `integer` 与 `number` 都生效，旧结构表达不了），
  并补齐 `properties` / `required` / `dependentSchemas` / `contains` / `multipleOf` / `format` 等。
- 泛型组件注册的结构比较对键序脱敏：`{a:1, b:2}` 与 `{b:2, a:1}` 此前会被判成「两种不同结构」
  而报冲突，现在比较前递归按键序归一。
- `BooleanValueRule` 的判定口径暴露为 `accepts()`：联合类型的 bool 分支复用同一份实现 ——
  同一个布尔口径写两遍时，两处漂移不会报错，表现只是两个字段对同一输入给出相反结论。
- **文档侧的必填推断不再自己算一遍**：`DTOSchemaFactory::objectFragment()` 改为读
  `DTOMetadataFactory` 的结论。两处各写一遍时，「`#[Required]` / 构造器默认值 / `#[Optional]`」
  三者的优先级只要有一侧改动，同一个 DTO 就会在文档与校验上得到不同的 `required` 列表 ——
  而这两处恰好是客户端与服务端各自的依据。
- `TypeExpression::parse()` 对 `?Foo` / `Foo|Bar` 给出归因正确的报错：这两种是 PHP 自己的写法，
  类型表达式不支持（可空的表达位置是属性的 PHP 类型）。此前 `?FooDTO` 会被当成类名一路走到
  「类请写完整类名（如 `\App\Foo\DTO\BarDTO`）」，写的人于是去反复检查类名，而真实原因在别处；
  `?Foo[]`、`list<?Foo>`、`array<string, ?Foo>` 这些嵌套写法同样归到这里。
  `parseOrLeaf()`（请求热路径）的容忍度不变：认不出的写法仍退化成「按类型名原样处理」。
- 修掉 `DTOSchemaFactory::fragmentForTypeName()` 上重复挂着的两个 docblock（给 `$nested`
  补注释时留下的编辑痕迹；PHP 只认最后一个，前一段成了死注释）。
- **跨模块的规则符合性收尾**（不按模块、按仓库自己的硬规则全仓扫了一遍）：
  - 9 处英文异常信息改中文并带上定位：`apidoc` 的响应 DTO 校验，`core` 的响应 DTO / 编码器 /
    转换器 / 参数解析器契约校验、`BusinessException` 的成功码校验、集成凭证未配置
    （后者现在直接报出配置键 `integration.integration_token`）。
  - 3 处校验规则的英文**兜底**文案改中文（`validation.enum` / `validation.boolean` /
    `validation.integer` 在语言键缺失时才会走到的那一段）。
  - 7 处 `ConfigProvider` 的发布描述改中文（`vendor:publish` 的列表里会打印）。
- **`metrics` 与 `websocket` 里 9 处绕过 `Time::now()` 的时间取值**统一到同一个入口：这些地方
  此前直接 `time()`（限流窗口、空闲连接判据、指标采样），而 `CarbonImmutable::setTestNow()`
  对它们无效 —— 涉及超时与过期的用例只能靠真实等待。其中两处刻意不同，并已在注释里写明：
  `GaugeCollectorRegistry` 的缓存 TTL 是「时长」而不是「时间点」，改用单调时钟（墙上时钟回拨
  不会让缓存提前失效）；`RateLimiter::nowMilliseconds()` 则必须与其它 worker 的窗口边界对齐，
  仍用墙上时钟，只是改走 `Time::now()`。
- `hyperf-apex/reliability` 已发布迁移里的 `->useCurrent()` 保持不变：它们随 2.0.0 发布，
  按约定不再修改；运行时的写入路径已由 `AbstractRepository` 与模型统一补时间。

- 控制器发现规则重写：不再要求路径里含 `/Controller/`，也不再从文件内容里用正则抽类名 ——
  前者会让放在别的目录下的控制器静默从文档里消失，后者遇到 `app/Controller/` 下的
  trait / interface / enum 会抛「无法解析出类名」把整个命令拦下。现在扫描全部 `.php` 并用
  token 判定类声明（`::class` 具体化与匿名类 `new class` 都不会被误认）。
  `api_doc.app_path` 不存在时也改为报出该配置键，而不是原生 `UnexpectedValueException`。
- 新增**悬空 `$ref` 的构建期断言**：`#[Discriminator]` 的 mapping 按短类名直接拼引用、不注册
  组件，mapping 里的类必须同时出现在 `#[OneOf]` 里。漏写时产物里留下指向不存在组件的引用，
  而编译器自带的 `validate()` 只做五项检查、查不到它。

### 新增

- `hyperf-apex/core` 新增 `publish/integration.php`：`integration.integration_token` 一直被
  `IntegrationTokenMiddleware` 读取，但没有任何配置模板承载它 —— 应用只能从源码里猜这个键，
  而漏配的表现是集成接口直接抛「集成凭证未配置」（报错里现在已带上键名）。
  模板的注释同时写明：未配置时中间件**拒绝**而不是放行，「没配就没人管」是最危险的默认值。
- `Hyperf\Apex\DTO\Contract\DocumentationOnlyAttribute`：标记「不参与请求校验」的 Schema Attribute。
  声明式约束有「文档」与「请求校验」两条落地路径，而结构关键字（`patternProperties`、
  `dependentRequired`、`propertyNames`、`unevaluated*`）、边界关键字（`exclusiveMinimum`、
  `uniqueItems`、`contains`）、纯注解关键字（`format`、`const`、`title`、`example`…）以及
  `#[IntAsString]`（只影响出站序列化）在 Hyperf 的校验体系里都没有对应规则，只能落在文档一侧。
  33 个约束 Attribute 已标注，并新增 `test/DTO/SchemaAttributeContractTest.php` 强制
  「每个 SchemaAttribute 必须在『参与校验』与『不参与校验』之间表态」——
  只实现 `SchemaAttribute` 又不表态，等于「看起来会校验、实际不校验」，使用方按文档以为服务端
  会拒绝、实际照单全收。`#[MapOf]` 是唯一例外：它自己不产出校验规则，但值层确实会被校验
  （元素类型表达式），因此列在测试的间接校验白名单里。
- 启动期访问声明检查 `PermissionDeclarationCheckListener` 与它的配置模板 `publish/security.php`
  （见「破坏性变更」里的说明）：漏标口径的端点此前既不会被拦下，文档侧又会被描述成公开接口。
- `AbstractRepository::paginate()`：同一份条件同时产出当前页数据与总数（先 clone 出 count、
  再取当前页 slice），供分页响应使用。各仓储自己拼两遍条件时，漏掉一个 `where` 就会出现
  「total 是 100、list 只有 3 条」这类对不上的分页。
- 空闲连接回收（`websocket.idle_timeout`，默认 180 秒）：网络中断不会触发 `onClose`，连接行会一直占着
  定长索引表，写满后新连接被直接拒绝且不会自愈。现在收到任意消息即刷新活跃时间，超时连接由
  `WsIdleConnectionRecycleListener` 主动断开（协程服务器下 Swoole 的 `heartbeat_*` 配置不生效）。
- 单连接消息限流（`websocket.rate_limit`，默认 1 秒 50 条）：超限只回错误信封，不断开连接。
- 运行配置启动检查 `WsRuntimeCheckListener`：协程服务器多 worker（推送会静默丢失）、
  `max_frame_bytes` 越过 `package_max_length`（超限帧变成断连）、空闲回收与 Swoole 心跳双双缺失。
- 入口路由注册成功时输出 info 日志，便于定位「启动正常、握手 404」。
- `WsSession::fdOf()`：连接 id 的编解码收敛到一处，推送与空闲回收共用。
- `hyperf-apex/metrics` 新增两个 WebSocket gauge：`core_ws_connection_capacity`（连接索引容量）与
  `core_ws_idle_connections`（等待回收的空闲连接数）。前者让「连接数逼近容量」可以告警，
  后者让「空闲回收没在工作」可见；索引表不可用（未启用网关）时整组不输出，而不是给出一串 0。
- `Hyperf\Apex\Logging\LoggerChannels`：日志通道解析入口，通道缺失时退回 `default`（见「修复」里的
  说明）。组件要按用途分流日志时从这里取 logger，不要直接调 `LoggerFactory::get()`。

### 变更

- 删除 `hyperf-apex/core` 的 `publish/health.php`：它唯一的键 `health.redis_pool` 全仓没有任何
  读取点，注释却承诺「就绪探针（`/health/ready`）探测的 Redis 连接池」—— 而这个端点在仓库里
  根本不存在（`access_log` 里默认排除 `/health/*` 的规则说明它曾被规划过，那条排除保留：
  应用自行实现的探针仍能享受它）。要提供就绪探针得先把它实现出来，那属于功能而不是配置。
- `OutboxPublisher::sortKeys()` 的注释说明为什么**不复用** `ArrayNormalizer::sortKeys()`：
  两者的排序 flag 不同（`ksort` 默认 SORT_REGULAR 与 SORT_STRING 对数字字符串键的顺序不同），
  而摘要生成后已落库成 `idempotency_key` —— 换排序规则会让同一份载荷算出不同的键，
  已存在的事件不再被识别为重复，等于凭空多出一批重复投递。要统一必须连带处理存量数据。
- 客户端发来的二进制帧（opcode 2）直接回「只接受文本帧」的错误信封，不再丢给 JSON 解析。
- `WsEnvelopeDTO` 拒绝 JSON 数组形态的 `data`：此前会一路走到 payload 水合，报出来的错与真实原因无关。
- `WsPushService::pushToRoom()` 顺手剔除房间成员中连接行已消失的连接（惰性自愈），
  避免每次推房间都对一个不存在的 fd 白投一次。
- `hyperf-apex/metrics` 的 `composer.json` 用 `suggest` 声明可选的 `hyperf-apex/websocket`：
  `WebSocketGaugeCollector` 一直读取连接索引，但此前既没有声明依赖、也没有说明这是可选集成
  （未安装 websocket 时由注册处的 `class_exists` 过滤跳过）。
- `hyperf-apex/reliability` 的 `composer.json` 用 `suggest` 声明可选的 `hyperf-apex/metrics`：
  `OutboundRecorder` 在装了 metrics 时会上报出站指标，此前同样没有声明（未装时 `class_exists` 守卫跳过）。
- `IdempotencyService::claim()` 的时间口径统一走 `Time::now()`（此前用 `time()` / `date()`，
  与同文件的 `prune()` 两种口径）；测试可以用 `CarbonImmutable::setTestNow()` 冻结时间，
  接管与过期逻辑不必再靠真实等待。
- `hyperf-apex/reliability` 的 `composer.json` 移除 `hyperf/cache`：src 里零引用，属于残留声明。
- 修正 `AGENTS.md` 里命名空间归属的描述：`Hyperf\Apex\RateLimit\` 与 `Hyperf\Apex\Idempotency\`
  的**声明侧契约**在 `packages/core`（只有运行时实现在 `reliability`），照旧描述会把跨包引用误判成漏声明。

## [3.0.0] - 2026-10-12

本次为破坏性版本：清除从多端 SaaS 业务工程带入组件库的租户 / 代理商 / 系统端 / 设备端领域概念，
以及源码注释、配置模板与文档中残留的具体业务示例，组件只保留中性机制。应用侧升级前请先读「破坏性变更」。

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
- 删除 `Hyperf\Apex\WebSocket\Controller\DeviceWsGatewayController`：它把「设备端」这条业务端划分固化进了组件。
  `hyperf-apex/websocket` 不再内置任何入口，应用需自己声明 `#[WsGateway]` 入口 —— 否则启动期校验会报
  「至少需要一个 #[WsGateway] 入口」。

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
