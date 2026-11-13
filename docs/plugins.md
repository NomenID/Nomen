# 插件

插件是 `plugins/<slug>/` 目录加一份 `plugin.json`。装插件不改核心代码：目录放进 `plugins/`，
名字写进 `config/plugins.php` 的 `enabled`。

```bash
php bin/nomen plugins list          # 看装了哪些、加载成功没
```

## 状态三态

| 状态 | 意思 |
|---|---|
| 启用 | 正常加载 |
| 未启用 | 在 `plugins/` 里但不在 `enabled` 里 |
| 启用但加载失败 | 在 `enabled` 里但被跳过了，上一行有原因（版本不满足 / 缺 PHP 扩展 / 类不可调用） |

加载失败**不会让站点报错**，只在 `error_log` 里记一行。插件「突然没反应」先看这个状态。

## 随包与独立

- 随包五个：`pages`、`open-platform`、`geoip`、`totp-qr`、`search`。
- 不随包：`quick-central-login`、`site-notice`，自己 clone。
- 每个都是独立仓库、Apache-2.0，跟核心版本节奏无关。

清单见 [官方插件](plugins/index.md)。

## 配置文件放哪

插件配置在 `config/plugins/<slug>.json`，不在插件目录里。升级插件不会覆盖站长的设置。

```json
{"base_path": "/w"}
```

读的时候合并到默认值上，文件不存在就用默认值。后台「接入点」改路径时写的就是这个文件。

## 自己写

从骨架开始：<https://github.com/NomenID/plugin-skeleton>

| 主题 | 文档 |
|---|---|
| 目录、清单、第一次改动 | [写一个插件](plugin-dev/index.md) |
| 清单每个字段 | [清单字段](plugin-dev/manifest.md) |
| 钩子 | [钩子](plugin-dev/hooks.md) |
| 页面与路由 | [页面与路由](plugin-dev/pages-and-routes.md) |
| 后台页面 | [后台页面](plugin-dev/admin-pages.md) |
| 模板与资源 | [模板与资源](plugin-dev/templates-and-assets.md) |
| 建表与命令 | [迁移与命令](plugin-dev/migrations.md) |
| 站点 API | [站点 API](plugin-dev/api.md) |
| 多语言 | [多语言](plugin-dev/i18n.md) |
| 发版 | [发布插件](plugin-dev/release.md) |

钩子的完整清单在 [钩子参考](hooks/index.md)（那份是生成的，代码里删了钩子而文档没跟上时 CI 会报错）。

## 别做的事

- **不要 ALTER 核心表**。核心表结构不是插件 API，升级会变。
- **不要把 `vendor/` 塞进插件目录**。依赖装到项目根。
- **不要把密钥、令牌写进活动日志或通知正文**。
- **插件跑的是全权限 PHP**。只装信得过的。
