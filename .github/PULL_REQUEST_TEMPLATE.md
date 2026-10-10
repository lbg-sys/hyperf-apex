## 改动内容

<!-- 说明「做了什么」，以及「为什么这么做」。 -->

## 关联 Issue

<!-- 如 Closes #123 / Refs #123 -->

## 自检清单

- [ ] `composer62 cs-fix` 已实际运行（不是手工排版）
- [ ] `composer62 test` 通过
- [ ] `composer62 analyse` 通过（PHPStan level 6，0 error）
- [ ] 改动涉及公开 API（类名、方法签名、配置键）时，已同步 README 与配置注释
- [ ] 改动涉及 `publish/` 模板时，已在 `CHANGELOG.md` 写明应用侧需要手工同步哪一项
- [ ] 改动涉及请求 DTO、控制器签名或响应 DTO 时，已确认应用侧重跑 `api-doc:generate` 无新增告警
- [ ] 未使用 `SKIP_GIT_CHECKS=1` / `--no-verify` 绕过门禁（若绕过，原因写在下方）

## 备注

<!-- 需要 Reviewer 特别注意的点、绕过门禁的原因、后续待办等。 -->
