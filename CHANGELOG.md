# 更新日志

本文件记录 `hyperf-apex/*` 各组件包的对外变更，格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [Unreleased]

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

[Unreleased]: https://github.com/lbg-sys/hyperf-apex/compare/v1.0.0...master
[1.0.0]: https://github.com/lbg-sys/hyperf-apex/releases/tag/v1.0.0
