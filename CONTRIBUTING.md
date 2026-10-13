# 贡献指南

本仓库是 `hyperf-apex/*` 组件库的 Monorepo。开始之前请先读根目录 `AGENTS.md`——那是本仓库的
完整项目规范（包边界、DTO/枚举/迁移/OpenAPI 约定、提交与发布规则），本文件只讲协作动线。

## 环境准备

- PHP 8.4 + Swoole 6.2：**所有 PHP 命令用 `swoole62`，所有 Composer 命令用 `composer62`**，
  默认的 `php` 是 PHP 8.1，不满足要求。
- 一次性启用 Git 钩子（`commit-msg` 校验提交格式，`pre-push` 跑质检门禁）：

  ```bash
  git config core.hooksPath .githooks
  ```

- 安装依赖：`composer62 install`。

## 提交前必须通过的门禁

```bash
composer62 cs-fix     # 代码风格（提交前必须真跑，不能只手工排版）
composer62 test       # 单元测试
composer62 analyse    # PHPStan level 6，必须 0 error
```

三条都过才算完成。CI（`.github/workflows/ci.yml`）与 `pre-push` 钩子用的是同一套判据，
风格检查在 CI 里用 `--dry-run`。不得常态使用 `SKIP_GIT_CHECKS=1` 或 `--no-verify` 绕过；
确需跳过时必须在提交或合并说明里写明原因。

## 分支与提交

- `master` 始终可发布；结构性改动走 `feature/*`、`fix/*`、`chore/*` 短分支，经 MR 合入。
- 需要测试环境验收的改动先进 `test` 分支，验收通过再合入 `master`。
- 提交信息必须符合 Conventional Commits：`<type>(<scope>): <subject>`，
  `type` 取 `feat`/`fix`/`refactor`/`chore`/`docs`/`test`/`perf`/`revert`，
  `scope` 用包名（`core`、`apidoc`、`apidoc-apifox`、`websocket`、`reliability`、`metrics`）或跨包主题（`repo`、`docs`）。

## 改动时的同步项

- 改公开 API（类名、方法签名、配置键）时，同一变更里同步 README 与配置注释。
- 改 `publish/` 下的模板默认值只影响之后重新发布的应用，需在 `CHANGELOG.md` 写明应用侧要手工改哪一项。
- 改请求 DTO、控制器签名或响应 DTO 后，需在应用侧重跑 `swoole62 bin/hyperf.php api-doc:generate`。
- 新增包必须同时在根 `composer.json` 的 `repositories`、元包 `require`、`extra.hyperf.config`
  以及拆分工作流（`.github/workflows/split.yml` 的 matrix）中登记。

## 发布

1. 合并到 `master` 后更新 `CHANGELOG.md`（把 `Unreleased` 段落固化为版本号）。
2. `git tag -a vX.Y.Z -m "chore(release): vX.Y.Z"` 并推送 tag；tag 只增不改。
3. tag 推送会触发 `.github/workflows/split.yml`，把 7 个子包推送到对应的只读仓
   （`hyperf-apex-{core,apidoc,apidoc-apifox,websocket,reliability,metrics,wecom}`），Packagist 随之索引新版本。
4. 元包 `hyperf-apex/apex` 直接由本仓库（`lbg-sys/hyperf-apex`）发布，无需拆分。
