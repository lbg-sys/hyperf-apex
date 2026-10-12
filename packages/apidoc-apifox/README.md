# hyperf-apex/apidoc-apifox

把 `#[WsGateway]` / `#[WsRoute]` 声明渲染成 **Apifox 原生 `webSocketCollection`**，让长连接协议也能进接口管理平台。

- **依赖**：`hyperf-apex/apidoc`、`hyperf-apex/websocket`（安装本包会一并引入）
- **完整文档**：见主仓库 [hyperf-apex](https://github.com/lbg-sys/hyperf-apex)
- **变更记录**：见主仓库 [CHANGELOG.md](https://github.com/lbg-sys/hyperf-apex/blob/master/CHANGELOG.md)

## 安装

```bash
composer require hyperf-apex/apidoc-apifox
php bin/hyperf.php vendor:publish hyperf-apex/apidoc-apifox --id=config
```

## 命令与出口

```bash
php bin/hyperf.php api-doc:generate-apifox    # 生成 apifox.json
```

| 项 | 值 |
| :--- | :--- |
| 产物 | 与 OpenAPI 共用 `api_doc.output_path` 下的 `apifox.json`（默认 `BASE_PATH/runtime/api-doc`） |
| 出口 | `GET /api_doc/get_apifox`（未生成时回 404 并提示该跑哪条命令） |

## 配置项

| 键 | 说明 |
| :--- | :--- |
| `apifox.folder` | 导入 Apifox 后的默认目录名 |
| `apifox.status` | 接口状态（如 `developing`） |
| `apifox.tags` | 附带的标签列表 |

## 契约来源

文档内容**完全**由 websocket 包的声明推导，不在本包里手写：

| 来源 | 渲染成 |
| :--- | :--- |
| `#[WsGateway(path:, endpoint:, name:)]` | 一个 WebSocket 连接（含地址与说明） |
| `#[WsRoute('{domain}.{action}')]` 及其 payload / 返回 DTO | 该连接下的各条消息（请求与响应示例） |
| `WsBuiltinEvent` | 内置事件（`sys.ping` / `sys.pong` / `sys.subscribe` / `sys.unsubscribe` / `sys.error`） |

处理器上标了「测试探针」之类的内部事件不会被渲染进对外契约 —— 文档只反映真实的协议面。
