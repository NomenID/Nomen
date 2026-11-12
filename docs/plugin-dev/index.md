# 写一个插件

插件是一个目录 `plugins/<slug>/`，里面有 `plugin.json`。装插件不用改核心代码：放进 `plugins/`，把目录名写进
`config/plugins.php` 的 `enabled`。

这篇从骨架仓库开始，一步一步改出一个能用的插件。后面几篇分别讲清单、钩子、页面、后台、模板、迁移、API 和多语言。

## 一、拿到骨架

```bash
git clone https://github.com/NomenID/plugin-skeleton plugins/example
```

或者直接复制一份目录。骨架里已经有一个特殊页、一个钩子和一份样式表：

```
example/
├── plugin.json            清单
├── src/Plugin.php         boot + 钩子处理函数
├── templates/status.php   /special/example 的正文
├── assets/example.css
├── i18n/en.json
├── i18n/zh-hans.json
└── LICENSE
```

启用它：

```php
// config/plugins.php
return [
    'enabled' => ['example'],
];
```

访问 `/special/example`，能看到骨架自带的那个小表格；登录页上方会多一行提示。

```bash
php bin/nomen plugins list
```

会列出所有插件和三态：`启用` / `未启用` / `启用但加载失败`。加载失败的那行上面通常还有一行原因，比如
`要求 Nomen >=1.5.0，当前 1.3.10`。

## 二、请求进来时发生了什么

1. `public/index.php` 调 `Plugins::boot()`。
2. 读 `config/plugins.php` 的 `enabled`，逐个读 `plugins/<slug>/plugin.json`。
3. 校验 `requires`（Nomen 版本、PHP 扩展）。不满足就跳过，`error_log` 里记一条，页面上什么都不显示。
4. 注册 `autoload` 的 PSR-4 前缀，然后调 `boot` 回调，再挂 `hooks`、`specialPages`、`adminPages`、`mounts`。
5. 核心路由注册在插件路由之后，所以插件盖不掉 `/login`、`/oauth/*` 这些入口。

`boot` 只跑一次，适合注册路由、后台页面、CLI 命令。**不要**在 `boot` 里查库、发信、写日志——每个请求都会跑它。

## 三、第一次改动：换个 slug

骨架里的 slug 是 `example`，换成你自己的名字（比如 `bookmarks`）要动四处：

| 位置 | 改什么 |
|---|---|
| 目录名 | `plugins/bookmarks/` |
| `plugin.json` | `name`、`description`，`autoload` 的前缀、`boot` 和钩子里的类名 |
| `src/Plugin.php` | `namespace Nomen\Plugin\Bookmarks;` |
| `Assets` 的登记 | `Plugins::style('bookmarks', 'bookmarks.css')`，资源文件名一起改 |

命名空间前缀习惯是 `Nomen\Plugin\<首字母大写的 slug>`，目录是 `src/`。类名和文件名要对上，PSR-4 才找得到。

## 四、改这一页

`src/Plugin.php` 的 `renderStatus()` 返回 HTML 字符串，核心把它放进特殊页外壳里：

```php
public static function renderStatus(): string
{
    return Response::viewPartial('@example/status', [
        'version' => self::version(),
    ]);
}
```

`Response::viewPartial('@<slug>/<模板>', $数据)` 渲染 `templates/status.php` 并把结果当字符串返回。数据用关联数组传，
模板里直接当变量用（`extract()` 过的）。

模板里所有输出都要转义，这是硬要求：

```php
<h2><?= htmlspecialchars(t('Bookmarks')) ?></h2>
```

## 五、改文案要改词典

`t()` 的键是**英文原句**，不是一个短标识。`t('Bookmarks')` 在英文站原样输出，在中文站去
`i18n/zh-hans.json` 找 `"Bookmarks"` 对应的译文；找不到就回落到英文原句。

所以新加一句文案要做两件事：代码里写 `t('English sentence.')`，然后把这一句加到 `i18n/en.json`（键=值）和
`i18n/zh-hans.json`（键=中文译文）。其余语言缺了只会回落成英文，不会报错。

## 六、调试

- **页面白屏 / 500**：看 PHP 错误日志（`/www/wwwlogs/<域名>.error.log`，本地看 `php -S` 的输出）。插件加载失败
  不一定是致命错误，`plugins list` 里会标出来。
- **改了没反应**：opcache 开着且关了时间戳校验时要 reload PHP-FPM；纯 PHP 文件保存后刷新通常就生效。
- **路由 404**：`Plugins::route()` 只在 `boot` 里有效；挂载点在 `plugin.json` 里声明了也要自己注册路由。
- **钩子没跑**：先用 `php bin/nomen plugins list` 确认插件是 `启用`，再看清单里 `hooks` 的类名和方法名是否拼对。

## 七、别做的事

- **不要 ALTER 核心表。** 核心表结构不是插件 API，升级时会变。要存数据就用插件自己的表（见
  [迁移与命令](migrations.md)）。
- **不要把 `vendor/` 塞进插件目录。** 依赖装到项目根，插件直接用自动加载。
- **不要把密钥、Token、密码写进 `ActivityLog` 或通知正文。** 后台和 CSV 都会把它们导出来。
- **插件跑的是全权限 PHP**，等于站点代码权限。只装信得过的插件。

## 接下来

| 想看什么 | 去哪 |
|---|---|
| `plugin.json` 每个字段 | [清单字段](manifest.md) |
| 挂钩子改行为 | [钩子](hooks.md) |
| 加页面、加路由、加公开入口 | [页面与路由](pages-and-routes.md) |
| 加后台页面 | [后台页面](admin-pages.md) |
| 模板与静态资源 | [模板与资源](templates-and-assets.md) |
| 建表、跑迁移、注册命令 | [迁移与命令](migrations.md) |
| 给 `/nomen-api` 加模块 | [站点 API](api.md) |
| 多语言 | [多语言](i18n.md) |
| 发版 | [发布](release.md) |
