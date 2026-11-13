# 皮肤

皮肤决定站点长什么样，不碰路由、不碰数据。一个皮肤是 `skins/<slug>/` 目录加一份 `skin.json`。

## 启用与选择

```php
// config/skins.php
return [
    'default' => 'nomen-2026',
    'enabled' => ['nomen-2026', 'nomen-ssr'],
];
```

- `default`：未登录访客看到的，`DEFAULT_SKIN` 环境变量优先。
- `enabled`：用户能在设置页里选的。整个键不写表示不限制。
- `default` 指向不存在的皮肤时，自动落到列表里第一个可用的。
- 用户选择优先：账号设置 → cookie `nomen_skin` → 站点默认。

## 三个布局必须都在

| 布局 | 用在哪 |
|---|---|
| `default` | 所有直出页面 |
| `app` | 登录后的应用区（Vue 外壳） |
| `admin-app` | 后台（Vue 外壳） |

**核心没有布局兜底**：缺一个只会回退到默认皮肤的同名布局；如果这个皮肤自己就是默认皮肤，那个页面直接 500。

## 官方两个

| 皮肤 | 定位 |
|---|---|
| [nomen-2026](skins/nomen-2026.md) | 默认：星野背景、卡片式认证页 |
| [nomen-ssr](skins/nomen-ssr.md) | 轻量：无星图、文字优先、窄栏 |

两个都用核心的 `public/css/style.css`，皮肤本身不带样式表——改配色靠 CSS 变量。

## 自己写

从骨架开始：<https://github.com/NomenID/skin-skeleton>

| 主题 | 文档 |
|---|---|
| 目录、安装、第一次改动 | [写一个皮肤](skin-dev/index.md) |
| 布局的职责和必备调用 | [布局](skin-dev/layouts.md) |
| 覆盖模板的查找顺序 | [模板覆盖](skin-dev/templates.md) |
| CSS 变量、深浅色、RTL、`?v=` | [静态资源](skin-dev/assets.md) |
| 发版与启用规则 | [发布皮肤](skin-dev/release.md) |
