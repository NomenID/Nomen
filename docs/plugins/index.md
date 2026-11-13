# 官方插件

随发布包发的是这五个：`pages`、`open-platform`、`geoip`、`totp-qr`、`search`。另外两个（`quick-central-login`、
`site-notice`）不在包里，按需自己装。

每个插件都是**独立仓库、Apache-2.0**，跟核心的版本节奏无关。启用方式都一样：目录放进 `plugins/`，
名字写进 `config/plugins.php` 的 `enabled`。

| 插件 | 默认启用 | 干什么 | 需要额外东西 |
|---|---|---|---|
| [pages](pages.md) | 是 | 站内文档：在站点里写 Markdown 页面 | 无 |
| [open-platform](open-platform.md) | 是 | 开发者门户：让别人自助建 OAuth 应用 | 无 |
| [geoip](geoip.md) | 是 | 按 IP 查国家 / 地点 | **要自己装 geoip2 和 mmdb** |
| [totp-qr](totp-qr.md) | 是 | 在浏览器里画两步验证二维码 | 无 |
| [search](search.md) | **否** | 站内搜索 | 可选外部引擎 |
| [quick-central-login](quick-central-login.md) | 不随包 | 第一方应用免确认登录 | 核心 ≥ 1.3.2 |
| [site-notice](site-notice.md) | 不随包 | 登录页 / 首页的提示条 | 核心 ≥ 1.5.0 |

## 装一个不随包的

```bash
git clone https://github.com/NomenID/plugin-site-notice plugins/site-notice
```

再写进 `enabled`，然后：

```bash
php bin/nomen plugins list      # 确认是「启用」不是「启用但加载失败」
```

## 排查通用三步

1. `php bin/nomen plugins list` —— 有没有加载、被什么条件跳过（版本 / PHP 扩展）。
2. 看 `error_log`，插件加载失败会写一行原因。
3. 看这个插件的页面：清单里 `mounts` 的路径、`specialPages` 的 slug。

被跳过最常见的原因是 `requires.Nomen` 不满足。核心升级后如果某个插件突然「没反应」，先看这条。
