# Site Notice：登录页提示条

在登录页、注册页头部下方挂一条提示。官方演示站用它说明「这个站的账号只属于本站，不是 CaelLabID」。

**不随发布包分发**。文案写在代码里，是给演示站用的；别的站要用就改文案或者自己写一个。

## 装上

```bash
git clone https://github.com/NomenID/plugin-site-notice plugins/site-notice
```

```php
'enabled' => ['open-platform', 'geoip', 'pages', 'site-notice'],
```

要求核心 **≥ 1.5.0**。

## 它做什么

挂 `auth.notice`：登录页 / 注册页头部下方输出一段 HTML。有人已经写过内容就不覆盖（返回原值）。

还登记了 `homepage.notice` 钩子，但**核心目前不发这个事件**，等于休眠——首页的提示是另一条路
（后台「首页」的设置项），别指望这个插件在首页显示东西。

## 改文案

文案在 `src/Plugin.php` 里，是一个 `t()` 键：

```php
t('This is a demo site. Accounts created here belong to this site only, not to CaelLabID.')
```

改它要同时改 `i18n/` 下 25 份词条（键就是这句英文）。改完跑：

```bash
php docs-internal/i18n/extract.php --check
```

## 样式

`assets/notice.css`，用站点变量取色，深浅色都跟得上。想换样子改这个文件；想让不同页面不同样子，
传的 class 是 `site-notice` 和 `site-notice--plain`。

## 坑

- **别用它做真正的公告系统**。它只在登录 / 注册页出现，没有「关闭」按钮，也不跟踪已读。
  要公告用 `auth.notice` 钩子自己写一个，或者后台首页设置项。
- **插件停用后提示立刻消失**，不需要清缓存。
- **文案里的域名**（`id.caellab.com`）是写死的，站点换域名要改。

## 用它当模板

想写自己的提示插件，这个插件是最小的完整例子：一个钩子 + 一个 CSS + 25 份词条，没有路由、没有表。
复制它改文案比从骨架开始还快。
