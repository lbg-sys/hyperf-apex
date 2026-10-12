# 项目规范

本文件约束本仓库（`hyperf-apex/*` 组件库，Monorepo）的代码与协作方式。
本仓库只有组件与配置模板，不含 `app/` 业务代码；下文提到的「应用」指通过
`composer require hyperf-apex/*` 引入这些组件的 Hyperf 工程。

## 项目运行环境

- 所有 PHP 命令必须使用 `swoole62`，不要使用默认的 `php`；默认 `php` 是 PHP 8.1，不满足本项目环境要求。
- 所有 Composer 命令必须使用 `composer62`，不要使用默认的 `composer`。
- `composer62` 通过 `swoole62` 运行 Composer，当前对应 PHP 8.4 和 Swoole 6.2。
- Composer 脚本需要启动 PHP 程序时，使用 `@php`，确保继承运行 `composer62` 的 PHP 解释器。
- 示例：使用 `swoole62 bin/hyperf.php list`、`composer62 install`、`composer62 test`、`composer62 analyse`、`composer62 cs-fix`。
- 每次修改 PHP 代码后，汇报前必须运行 `composer62 cs-fix`，不得只依赖手工格式化。
- `cs-fix` 完成后必须继续运行 `composer62 test` 和 `composer62 analyse`，避免格式化改动引入回归。

## 仓库结构与包边界

- 目录约定：`packages/{package}/src/` 放源码（PSR-4 与包内 `composer.json` 的 `autoload` 一致）、`packages/{package}/publish/` 放 `vendor:publish` 出去的配置与迁移模板、`test/` 放用例（按包分子目录）；根目录元包 `hyperf-apex/apex` 聚合全部组件。
- 包名与命名空间不总是一对一：`hyperf-apex/reliability` 一个包内含 `Hyperf\Apex\RateLimit\`、`Hyperf\Apex\Idempotency\`、`Hyperf\Apex\Outbox\`、`Hyperf\Apex\Notify\`、`Hyperf\Apex\Outbound\` 多个命名空间。
- 依赖方向：`packages/core` 只依赖 Hyperf 基础组件，不得依赖其它 Apex 包；其余包按需依赖 `core`。包之间只允许单向依赖，不得成环。
- **`src/` 里 `use` 了别的包，就必须在包内 `composer.json` 的 `require` 里写出来**。本仓库是 monorepo，根 autoload 让所有包互相可见，漏声明在仓库内跑得通、独立安装却会「Class not found」—— README 的「按需引入」正是靠这条成立。
- 新增包必须：`composer.json` 声明 `license: Apache-2.0` 并提供 `ConfigProvider`（注册 `annotations.scan.paths` 与 `publish`），同时在根 `composer.json` 的 `repositories`、元包 `require` 与 `extra.hyperf.config` 中登记。

## 数据库设计规范

- 本仓库只提供迁移模板，放在 `packages/{package}/publish/migrations/`，由应用用 `vendor:publish hyperf-apex/{package} --id=migrations` 发布后执行；本仓库自身不连数据库、不跑迁移。
- 迁移使用 Hyperf 版本化迁移，必须同时提供 `up()` 与 `down()`。
- 整仓尚未发布（没有 git tag）时，迁移模板可以原地修改；一旦打了版本 tag，已经发布出去的迁移文件不得再修改或重命名，只能追加新迁移。
- 所有数据表和字段都必须提供清晰、准确的中文注释。迁移创建表或新增字段时，必须通过迁移 API 的 `comment()` 添加注释；后续修改现有表时，应同时为缺失注释的表或字段补齐注释。
- 所有数据库时间字段统一使用 `_time` 结尾，例如 `create_time`、`update_time`、`dispatch_time`；不使用 `created_at`、`updated_at`。
- 默认不增加软删除字段，不使用 `deleted_at`。确需软删除时字段名用 `delete_time` 并在 Model 上启用 `SoftDeletes`，目的是让历史记录与租约保持可读；手写 join 不受模型全局 Scope 保护，这类查询必须显式排除 `delete_time` 非空的行。
- 不使用数据库外键，表之间只保留逻辑关联 ID，并通过普通索引提升查询性能。
- 表前缀由应用的数据库连接配置统一管理，迁移中的逻辑表名不得手动拼接前缀。

## HTTP 接口路由规范

- HTTP 接口只使用 `GET` 和 `POST` 方法。
- URL 路径使用小写蛇形命名，例如 `get_list`、`get_detail`、`replace_sources`；不使用驼峰命名。
- URL 路径必须包含明确的动作名称，不使用空路径作为业务动作，例如使用 `/get_list`、`/create`、`/update`、`/delete`。
- 资源 ID、关联 ID 等参数不得放在 URL path 中，不使用 `{id}` 形式的路径参数。
- `GET` 请求参数放在 query 中，`POST` 请求参数放在 JSON body 中。

## 控制器与访问声明

- 本仓库的控制器放在 `packages/{package}/src/Controller/`，路由由包内 `ConfigProvider` 的 `annotations.scan.paths` 注册；控制器是**运行时能力**，与「是否进业务接口文档」无关（见「OpenAPI 文档规范」）。
- 权限归属（哪个端、哪套权限体系）是应用侧的组织方式，本仓库只提供机制：`#[RequirePermission]`、`#[RequireRole]`、`#[PublicAccess]`、`#[Authenticated]`、`#[IntegrationAccess]`。组件不替应用决定某个端该用哪种权限；应用需要区分多套权限体系时，用 `#[RequirePermission(guard: '...')]` 自行标注命名空间。
- 组件自己的端点必须显式声明访问口径，不留「没标注就等于没人管」的中间态：对外文档端点是 `#[PublicAccess]`（是否真正对外由部署网络与网关决定），需要身份的端点用 `#[Authenticated]`，只允许运维来源的端点用来源白名单（如 metrics 的 `allow_ips`）。
- 新增不鉴权的端点，必须在 README 与配置注释里写明「为什么不鉴权、靠什么限制来源」。

## 请求参数校验规范

- 控制器不得直接执行参数格式校验，不在控制器中编写 `requiredId()`、`filter_var()` 等手工校验逻辑。
- 统一使用强类型 DTO 承载请求参数，在控制器方法参数上通过 `#[FromQuery]`、`#[FromBody]`、`#[FromMultipart]` 区分 GET query、POST JSON body 和文件上传表单；不额外定义 Request 类。
- 请求校验规则由 DTO 属性类型和 Attribute 自动生成；具体控制器不重复编写规则，也不使用数组下标或手动类型转换。
- 校验规则产生的文案取自应用的语言包（`config/autoload/translation.php`）；本仓库不内置语言包。组件自己抛出的、要回给客户端的提示（如参数解析失败）直接用中文写在代码里，不另建一套文案映射表。
- DTO 只负责输入格式、类型、必填、范围、枚举和字段结构校验；资源是否存在、唯一性、状态流转、权限和其他业务不变量由 Service 层负责。

## DTO 设计规范

- 请求和响应都使用明确的强类型 DTO：应用侧放自己的 DTO 目录，本仓库的 DTO 放 `packages/{package}/src/**/DTO/`。
- DTO 使用 `final readonly class`、构造器属性提升和明确的 PHP 类型；不使用动态数组属性承载接口数据。
- DTO 属性名与实际接口字段名保持一致。需要向 Repository 传数组时，只允许在 Service 内部转换。
- **DTO 属性名使用小写驼峰**，例如 `$contentType`、`$coverFileId`、`$createTime`；不使用蛇形，即使对应的数据库列名是蛇形。请求参数水合和响应序列化都按属性名直接匹配，不做命名转换——属性名就是接口字段名，改属性名即改接口契约。
- 数据库列名保持蛇形，两种命名的转换在 Service 内完成：`['cover_file_id' => $dto->coverFileId]`。响应 DTO 的 `fromModel()` 同理，读 Model 的蛇形列、赋值给驼峰属性。
- 例外只有「字段名真正由外部契约固定」的场景，例如第三方回调载荷里对方定义的字段（七牛回调的 `key`、`hash`、`fsize` 来自对方的 callbackBody 模板变量）。这类属性要在类注释里写明受外部约束。**自己拼进请求或回调体的字段不算例外**——两端都在我们手里就用驼峰，改名时同步发送方。
- 创建、更新、列表查询、详情、删除和响应使用各自语义明确的 DTO，不使用一个 DTO 混合多个操作。
- 不使用 `IdDTO`、`CommonDTO`、`DataDTO` 等语义模糊的公共 DTO。使用领域和操作明确的名称，例如 `QuestionDetailDTO`、`QuestionDeleteDTO`、`AnswerTaskListDTO`。
- 列表查询必须使用独立查询 DTO，例如 `TaskListDTO`。新增筛选条件时扩展查询 DTO，不增加 Service 的散乱标量参数或无类型过滤数组。
- 嵌套数组使用嵌套 DTO，并通过 `#[ArrayOf(ItemDTO::class)]` 声明元素类型；不在 Service 公共方法中传递未经水合的业务数组。
- 响应 DTO 负责隔离 Model 和接口输出，Service 不直接向 Controller 返回数据库 Model。
- DTO 可以提供 `fromModel()` 等明确的转换方法，但不得在 DTO 内执行数据库查询或业务状态变更。

## 枚举规范

- 项目枚举必须使用 PHP backed enum，明确声明 `int` 或 `string` backing type；不使用无 backing value 的 UnitEnum。
- 所有枚举必须实现 `Hyperf\Apex\Enum\Interface\EnumInterface` 并使用 `Hyperf\Apex\Enum\Traits\EnumTrait`。
- 所有项目枚举必须添加 `#[Hyperf\Constants\Annotation\Constants]`。
- 每个枚举 case 必须通过 `#[Hyperf\Constants\Annotation\Message('中文含义')]` 配置清晰的中文名称，不在业务代码中重复维护枚举文案映射。
- 枚举值一旦被数据库或接口使用，不得随意修改；需要调整展示名称时只修改 `Message`。
- 枚举校验使用 DTO 上的 `#[EnumValue(EnumClass::class)]`，Service 内需要业务判断时优先使用枚举自身的 `transform()`、`isValidValue()`、`equals()` 等统一能力。
- 枚举选项输出统一使用 `EnumClass::getOptions()`，不手写 `value => label` 数组。
- 枚举序列化遵循 `EnumTrait` 的 `{key, name}` 结构；接口只需要 backing value 时显式使用 `$enum->value`。

## DTO 校验与文档 Attribute 规范

- DTO 字段约束 Attribute 放在 `packages/core/src/DTO/Attribute/`；接口级文档 Attribute（`#[ApiOperation]`、`#[ApiFolder]`、`#[ApiHeader]`、`#[ApiError]`、`#[FileField]`）放在 `packages/apidoc/src/Attribute/`，两者不要混放。
- 字段说明使用 `#[FieldDescription('中文说明')]`，请求和响应字段都应提供清晰的中文说明。
- 字符串长度使用 `#[Length(min: ..., max: ...)]`。
- 数值范围使用 `#[Minimum(...)]`、`#[Maximum(...)]`，不要用 `Length` 表达数值范围。
- PHP Enum 使用 `#[EnumValue(EnumClass::class)]`。
- `EnumValue` 生成 OpenAPI 时必须同时包含 backing value、中文值说明和 case 名称；字段已有 `FieldDescription` 时应合并说明，不得覆盖原字段含义。
- 固定值集合使用 `#[InValues([...])]`，排除值集合使用 `#[NotInValues([...])]`。
- 日期使用 `#[DateFormat]` 或指定校验格式的 `#[DateFormat(validationFormat: '...')]`。
- 正则约束使用 `#[Pattern('...')]`，文档示例使用 `#[Example(...)]`。
- 数组长度使用 `#[ArraySize(min: ..., max: ...)]`，数组元素类型使用 `#[ArrayOf(...)]`。
- 请求侧的枚举数组使用 `#[ArrayOfEnum(SomeEnum::class)]`，它替代 `#[ArrayOf('string')]`，不要同时标注：元素在文档里是带 `enum` 的 backing value、校验规则挂在 `字段.*` 上、水合时按 backing 类型转型。与标量字段上「PHP 类型写 `string` + `#[EnumValue]`」的写法一致。响应侧返回枚举数组时仍用 `#[ArrayOf(SomeEnum::class)]`，那里的元素是 `EnumTrait` 序列化出的 `{key, name}` 对象。
- 键值映射结构使用 `#[MapOf('string')]`，不要让 `array<string, string>` 语义的字段落成裸 `type: array`。
- 默认通过构造器参数是否有默认值推断必填和可选；特殊情况使用 `#[Required]` 或 `#[Optional]`。
- 可省略但不允许显式传 `null` 的可空 PHP 属性使用 `#[NotNull]`，区分“未传字段”和“传入 null”。
- 其余 OpenAPI 3.1 / JSON Schema 2020-12 词汇按需使用，都在同一目录下：
  - 取值：`#[ConstantValue]`、`#[DefaultValue]`、`#[Examples]`
  - 标注：`#[Title]`、`#[Format]`、`#[Deprecated]`、`#[ReadOnlyValue]`、`#[WriteOnlyValue]`、`#[ExternalDocs]`、`#[Xml]`
  - 数值：`#[MultipleOf]`、`#[ExclusiveMinimum]`、`#[ExclusiveMaximum]`
  - 数组：`#[UniqueItems]`、`#[PrefixItems]`、`#[Contains]`、`#[UnevaluatedItems]`
  - 对象：`#[PropertyCount]`、`#[PatternProperties]`、`#[PropertyNames]`、`#[DependentRequired]`、`#[DependentSchemas]`、`#[UnevaluatedProperties]`
  - 组合：`#[AllOf]`、`#[AnyOf]`、`#[OneOf]`、`#[Conditional]`、`#[Discriminator]`
  - 二进制内容：`#[ContentMediaType]`、`#[ContentEncoding]`
- 组合类 Attribute 的参数写「类型引用」——DTO 类名会解析成 `$ref` 并自动注册组件，标量类型名（`string`/`integer` 等）解析成基础类型；不要在 Attribute 参数里内联 schema 数组。`#[Discriminator]` 的 mapping 值同样写 `DTO::class`，不写组件名字符串。
- 所有写「类型引用」的位置（`#[ArrayOf]`、`#[MapOf]`、组合类 Attribute、`@return` 注释）都接受**类型表达式**，由 `Hyperf\Apex\DTO\Metadata\TypeExpression` 统一解析：`list<X>`、`array<X>`、`array<string, X>`（映射）、`array<int, X>`（数组）、`X[]`，可任意嵌套。`#[ArrayOf('list<int>')]` 会同时作用于文档的嵌套 `items`、校验规则的 `字段.*.*` 和水合时的逐层转型，三层深度一致。
- 不支持 `array{a: int}` 数组形状、泛型参数内的联合类型（`list<int|string>`）和未知泛型基类（`Collection<X>`）——这些形状请改用嵌套 DTO。写在 Attribute 上会让文档生成直接失败，不会静默降级成 `type: string`。
- 类型表达式**内层**的类名必须写完整类名（`#[ArrayOf('list<\App\Module\Foo\DTO\BarDTO>')]`）：PHP 不对字符串里的类名做 `use` 解析。写短类名会在文档生成期直接报错。只有一层时照旧用 `#[ArrayOf(BarDTO::class)]`。
- 新增普通字段约束 Attribute 时，实现 `ValidationRuleAttribute` 和/或 `SchemaAttribute`，由 Attribute 自己提供 Hyperf 规则和 OpenAPI Schema 片段；不得在 `DTORuleFactory` 或 `DTOSchemaFactory` 中增加具体 Attribute 类名判断。约束作用在**数组元素**上时实现 `ItemRuleAttribute`，规则会挂到 `字段.*`。
- `SchemaAttribute::fragment()` 返回 `SchemaFragment` 值对象。片段必须在方法体内构建，参数校验也放在方法体内：`DTORuleFactory` 的规则按类缓存，但首个请求仍要在请求路径上执行一次 `newInstance()`，构造函数变重会拖慢首个请求、构造函数抛异常会把构建期错误变成请求期 500。
- `SchemaFragment` 的合并语义是「浅覆盖 + description 换行追加」：后声明的 Attribute 覆盖先声明的（`#[MapOf]` 依赖这一点把 `type: array` 盖成 `object`），而 `description` 是唯一追加而非覆盖的键。改成深合并会静默改坏文档。
- 合并完成后 `DTOSchemaFactory` 会做一致性收口，下面这些写法在生成期直接报错而不是产出骗人的文档：
  - 两个 Attribute 写同一个关键字（如 `#[Format]` 与 `#[DateFormat]` 抢 `format`）——产出会取决于声明顺序
  - 关键字与 `type` 不匹配（`minLength` 用在数组、`minItems` 用在被 `#[MapOf]` 改成 object 的字段上）——那是一条不会被任何校验器执行的死约束
  - 属性上重复标注 `#[ArrayOf]`——只有第一个会生效
  - 属性类型是接口、抽象类，或没有实现 `EnumInterface` 的枚举——没有确定的序列化形态
- 可空字段的 `enum` 会自动补上 `null`：`enum` 是穷尽的且对所有实例类型生效，少了 `null` 就会出现「`type` 允许 null 而 `enum` 不允许」，文档比服务端更严。
- 构造器参数的非 null 标量默认值会自动输出成 `default`，不需要额外标注；写了 `#[DefaultValue]` 时以 Attribute 为准。
- `DTORuleFactory` 和 `DTOSchemaFactory` 只负责类型推断、必填推断、Attribute 聚合和嵌套 DTO 遍历，不承载具体业务规则。
- PHP 类型与 wire 类型不一致的字段属性，用单一语义的 Attribute 表达，并实现 `TypedSchemaAttribute` 声明允许的源类型，由 `DTOSchemaFactory` 在构建期校验类型对；标错位置会让 `api-doc:generate` 直接失败。目前只有 `#[IntAsString]`，只允许 `int => string`：超过 JS `Number.MAX_SAFE_INTEGER`（2^53-1）的整数在浏览器端会静默丢精度，声明成字符串后前端拿到精确值。判据是「源类型 => 目标类型」而不是目标类型 —— `float => string` 受 `precision` ini 影响、`bool => string` 会产出空串，都不许进来。
- 这类 Attribute 只声明 cast，不声明 transform：金额（分转元）、时间格式化、编码解码都要计算，仍放在 `fromModel()` 或专门的类型里。`#[IntAsString]` 自带 `pattern`，因此与 `#[Pattern]` 互斥（两个 Attribute 抢同一个关键字会在生成期报错），同一字段也不要用 `#[Length]`。
- `exists`、`unique`、资源存在性、权限和状态流转不做成 OpenAPI 字段 Attribute，仍由 Service 处理。

## Controller 与 Service DTO 边界

- Controller 只负责在方法参数上接收请求 DTO、调用 Service 并直接返回响应 DTO，不拆解 DTO 字段后再传散乱参数，也不手工包装响应外层结构。
- Controller 方法的返回类型就是接口 `data` 的结构，必须写成明确的响应 DTO、`PaginationResultDTO` 或 `void`；返回 `array` 时必须声明元素类型。
- Service 的公开业务方法参数必须使用语义明确的 DTO，包括列表、详情、创建、更新、删除和批量操作。
- Service 之间调用公开业务方法时同样传对应 DTO，不传裸 `int` 或数组。
- Service 私有实现方法可以使用 `int`、字符串或数组，例如 `findModel(int $id)`；DTO 约束只针对公开业务边界。
- Service 返回响应 DTO、`void` 或 `list<XDTO>`。返回列表时必须写 `@return list<XDTO>`，让元素类型在静态检查与 IDE 里可见，控制器的 `#[ArrayOf]` 负责把它落进文档。不返回 Model，也不返回元素类型说不清的业务数组。
- Repository 保持持久化边界，可以接收数组和返回 Model；DTO 与 Model 的转换由 Service 或响应 DTO 负责。

## 事务边界

- **事务由 Service 开启，Repository 不做跨仓储的编排。** 一次业务操作要原子地写多张表时，由 Service 用 `Db::transaction()` 圈定范围，内部依次调用多个 Repository。Repository 只负责自己那张表的读写，以及单仓储范围内的事务（例如可靠性套件里幂等记录仓储的「行锁校验 + 插入」）。
- 因此 **Service 里出现 `Db::transaction()` 不违反上一条的「Repository 保持持久化边界」**：那条约束的是「谁能拼查询条件、谁能碰 Model」，不是事务编排。Service 仍然不写查询条件、不直接使用 Model 的查询构造器。
- 写入载荷里的表达式级 SQL 同理允许出现在 Service（例如自增计数 `Db::raw('attempt_count + 1')`）：它描述的是「写什么值」，不是「查哪些行」。时间值不在此列，一律由应用侧传入（`Hyperf\Apex\Support\Time::now()`），不用数据库的 `CURRENT_TIMESTAMP`。
- **事务内不做远程调用、不发消息、不投递。** 超时会拖住整个事务并拉长行锁持有时间，失败还会把已完成的写入一起回滚。需要触发的动作先落库（入队 / 待投递），提交之后再处理。

## OpenAPI 文档规范

- 目标版本是 OpenAPI 3.1，由应用侧 `config/autoload/api_doc.php`（`vendor:publish hyperf-apex/apidoc --id=config` 发布）的 `version` 决定；版本差异（可空类型、`const`、`prefixItems`、`if/then/else` 等）由编译层承担，业务代码只描述语义。
- 文档标题、版本、接口地址在 `config/autoload/api_doc.php` 的 `info` 与 `servers` 配置，不写死在生成器里。
- 控制器业务方法使用 `#[ApiOperation(summary: '接口中文名称', tags: ['分组'])]` 声明接口名称和分组。
- 控制器类使用 `#[ApiFolder('模块/子模块')]` 声明文档目录。
- **认证不需要标注**。`security` 与 `securitySchemes` 由 `packages/core/src/Security/` 下的访问声明（`#[PublicAccess]`、`#[Authenticated]`、`#[IntegrationAccess]`、`#[RequirePermission]` 等）自动推导，权限码同时输出为 `x-required-permissions`。不要用 `#[ApiHeader]` 声明 Authorization 或集成 Token —— `#[ApiHeader]` 只用于真正的业务请求头。
- **通用错误响应不需要标注**。400/422/500，以及接口需要认证（含集成 Token）时的 401、声明了权限码时的 403、声明了 `#[RateLimit]` 时的 429，都由生成器按 `ErrorCode` 枚举与访问声明自动补全 —— **补全判据是接口的访问声明，不是「谁会抛这个错」**（见 `packages/apidoc/src/Builder/ResponseBuilder.php` 的 `errors()`）。
- **本接口特有的错误必须用 `#[ApiError(...)]` 声明**，漏标等于文档宣称这些接口永远不会失败。生成器推不出来的有三种：资源不存在（404）、状态冲突（409），以及**没有权限码的接口**（`#[PublicAccess]` / `#[Authenticated]` / `#[IntegrationAccess]`）上 Service 直抛的 `PERMISSION_DENIED` —— 它们的 403 没有权限中间件兜底，不声明就彻底不可见。带权限码的接口不必重复声明 403（声明了产物也不变）。改动 Service 的抛错路径时要同步复查对应方法的 `#[ApiError]`。
- 控制器的 `#[Controller]` 必须显式写 `prefix`，mapping 必须写非空的相对 `path`。Hyperf 会在缺省时按类名/方法名推导、对绝对路径忽略 prefix，而生成器不复刻这些规则——写了会在生成期直接报错，避免文档 URL 与真实路由不一致。
- 响应 `data` 的 Schema 由控制器方法的返回类型自动推导，不使用额外的响应声明 Attribute。
- 无业务响应数据时返回类型使用 `void`，OpenAPI 中的 `data` 生成为 `type: null`，不额外定义空响应 DTO。
- 返回类型为 `array` 时使用方法级 `#[ArrayOf(ItemDTO::class)]` 声明元素类型；返回 `PaginationResultDTO` 等泛型 DTO 时使用 `#[ArrayOf(ItemDTO::class, property: 'list')]` 声明列表元素类型，并补充 `@return PaginationResultDTO<ItemDTO>` 注释。泛型的每种实例化会注册成独立组件，名字里带属性名（如 `PaginationResultDTO_list_ConfigDTO`）。
- 请求 Schema 从方法参数上的请求 DTO 自动生成；响应外层结构由 `ApiResponseDTO` 中唯一标记 `#[ResponsePayload]` 的属性确定。
- 文件上传用方法级 `#[FileField('file', '待上传的文件')]` 声明表单里的文件字段——文件不经过 DTO（Service 从 `RequestInterface::file()` 取），类型系统里没有痕迹，必须显式声明。
- 组件名取 DTO 短类名。不同命名空间的同名 DTO 会在生成时直接报错，需要重命名其中之一，不允许靠加前缀绕开。
- operationId 由「HTTP 方法 + URL path」确定性派生（如 `post_api_visibility_admin_account_devices_bind`），**不取自控制器短类名**。唯一性继承自 OpenAPI 对 `(path, method)` 的全局唯一要求：不同端（如 Admin 端与集成端）出现同名控制器不再需要重命名避让，客户端标识跟随 URL 走，控制器改名或挪目录不会破坏已发布文档。
- 生成命令在应用内执行：`swoole62 bin/hyperf.php api-doc:generate`（本仓库不提供控制台入口）。生成时会跑规格校验，**任何 error 或 warning 都会让命令失败**——上游把「`type: array` 但没有 `items`」这类骗人的文档归到 warning，放过它等于放过一份说了谎的契约。
- 编译器自带的 `validate()` 只做 5 项检查（`info`/`info.title` 存在、components 非空、license 字段互斥、`type: array` 缺 items、requestBody 用在不支持的方法上）。悬空 `$ref`、孤儿组件、`operationId` 重复、响应是否覆盖真实错误路径它都不管，这些由生成器自己的构建期断言和 `test/` 下的测试负责。
- 生成文件为 `runtime/api-doc/openapi.json`。
- 对外 JSON 地址为 `GET /api_doc/get_openapi`；不维护项目内置预览页面。
- 文档只扫描应用的 `app/` 目录，所以本仓库自带的控制器（`/api_doc/*`、长连接入口、metrics 端点）不会进业务接口文档，`ApiDocController` 另有一层硬编码排除做兜底。要排除应用自己的控制器或方法，在应用 `config/autoload/api_doc.php` 的 `excluded` 中按类名或 `类名::方法名` 配置，支持 `*` 通配符。
- 应用的请求 DTO、控制器方法签名、返回类型或响应 DTO 改动后，必须重新生成 OpenAPI 再跑测试。应用侧若维护 `test/Snapshot/openapi.json` 全量快照，预期变更用 `UPDATE_API_DOC_SNAPSHOT=1 composer62 test` 更新，并人工审查 diff。

## Git 协作与提交规范

- 主干分支为 `master`，保持始终可发布；结构性改动使用短生命周期分支（`feature/`、`fix/`、`chore/`）经 MR 合入，单人小改动可直推 `master`。
- 需要先给测试环境验收的改动先进 `test` 分支，验收通过再合入 `master`；`master` 只收确定发布的代码。
- 提交必须使用 Conventional Commits：`<type>(<scope>): <subject>`，`type` 取值 `feat`/`fix`/`refactor`/`chore`/`docs`/`test`/`perf`/`revert`；禁止 `update`、`fix bug` 等无法检索的表述。正文用空行分隔，说明「为什么」。`scope` 用包名（`core`、`apidoc`、`websocket`、`reliability`、`metrics`）或跨包主题（`repo`、`docs`）。
- 仓库提供 `.githooks`（一次性启用：`git config core.hooksPath .githooks`）：`commit-msg` 校验提交格式，`pre-push` 在推送 `master`/`test` 前跑风格检查、静态分析与单元测试，判据与 `.github/workflows/ci.yml` 一致。不得常态使用 `SKIP_GIT_CHECKS=1` 或 `--no-verify` 绕过，确需跳过时须在提交/合并说明中注明原因。
- 版本发布在 `master` 上打语义化 tag（`vX.Y.Z`），tag 只增不改；应用按 tag 升级，回滚即指回上一个版本 tag。

## 质检门禁与测试

- 每次修改 PHP 代码（含 `publish/*.php` 模板）后，汇报前必须依次运行 `composer62 cs-fix` → `composer62 test` → `composer62 analyse`，三条都过才算完成；汇报要给出实际结果，不能只说「已格式化」。
- 静态分析为 PHPStan level 6、扫描 `packages/`，必须保持 0 error。`phpstan.neon.dist` 里的 `ignoreErrors` 只用于框架自身的类型缺陷，新增条目要写清为什么无法用类型表达。
- 用例放在 `test/` 并按包分子目录（`test/ApiDoc/`、`test/RateLimit/`、`test/WebSocket/`……）。修 bug 时补一条「修复前会失败」的用例；新增能力时补覆盖边界与并发语义的用例。
- 单元测试不依赖真实 MySQL / Redis：需要外部依赖的能力用内存替身（见 `test/Idempotency/`、`test/WebSocket/`）；`Swoole\Table` 这类进程内共享内存可以直接用。
- 改动公开 API（类名、方法签名、配置键）时，在同一变更里同步 README 与配置注释。

## 公共 API 与配置模板

- `publish/` 下的文件是模板：`vendor:publish` 只做一次性拷贝，应用发布之后不再跟随升级。因此**改模板默认值只影响之后重新发布的应用**；要让已发布的应用也同步，必须在升级说明里写清需要手动改哪一项。
- 模板默认值取安全侧：默认关闭不鉴权的调试入口、默认只允许本机抓取指标、默认不放行任何额外来源。需要放开的地方，注释要写明放开后承担什么风险。
- 模板里不得出现具体业务的路由、表名、账号等信息：通用底座里写死业务默认值，别的项目拿到的就是一份含义错误的默认值。按项目变化的清单走环境变量（如 `ACCESS_LOG_EXCLUDE_PATHS`、`METRICS_ALLOW_IPS`）。
- 组件包新增能力或调整公开行为时，同步更新根目录 `README.md` 的模块清单与说明。
