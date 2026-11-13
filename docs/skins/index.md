# 官方皮肤

随包发两个，都是独立仓库、Apache-2.0：

| 皮肤 | 定位 |
|---|---|
| [nomen-2026](nomen-2026.md) | 默认皮肤：星野背景、卡片式认证页 |
| [nomen-ssr](nomen-ssr.md) | 轻量：无星图、无前端框架、纯 PHP 输出、窄栏阅读 |

## 启用与切换

`config/skins.php`：

```php
return [
    'default' => 'nomen-2026',
    'enabled' => ['nomen-2026', 'nomen-ssr'],
];
```

- `default`：未登录访客看到的皮肤，`DEFAULT_SKIN` 环境变量优先。
- `enabled`：用户能在设置页里选的那些。整个键不写表示不限制。
- `default` 指向不存在的皮肤时自动落到列表里第一个可用的。

用户的选择优先于站点默认：账号设置 → cookie `nomen_skin` → 站点默认。所以换默认皮肤**不影响已有用户**。

## 两个都有的

```
layouts/default.php     普通页面
layouts/app.php         登录后的应用区（Vue 外壳）
layouts/admin-app.php   后台（Vue 外壳）
partials/footer.php     页脚
i18n/                   皮肤特有词条
```

样式都复用核心的 `public/css/style.css`，皮肤本身不带 CSS 文件——改配色靠 CSS 变量，不靠覆盖样式表。

## 自己写一个

见 [写一个皮肤](../skin-dev/index.md) 和骨架仓
[NomenID/skin-skeleton](https://github.com/NomenID/skin-skeleton)。
