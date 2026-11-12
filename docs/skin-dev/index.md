# 写一个皮肤

皮肤决定站点长什么样，不碰路由、不碰数据。一个皮肤是一个目录 `skins/<slug>/`，里面有 `skin.json`。

这篇从骨架开始。后面几篇分别讲布局、模板覆盖、静态资源和发版。

## 一、拿到骨架

```bash
git clone https://github.com/NomenID/skin-skeleton skins/example
```

骨架里三个布局都在，页脚片段和两份词条也在：

```
example/
├── skin.json
├── layouts/
│   ├── default.php     普通页面（首页、登录、文档、特殊页）
│   ├── app.php         登录后的应用区（Vue 单页外壳）
│   └── admin-app.php   后台（Vue 单页外壳）
├── partials/footer.php
├── i18n/               25 份词条
└── LICENSE
```

启用：

```php
// config/skins.php
return [
    'default' => 'nomen-2026',
    'enabled' => ['nomen-2026', 'nomen-ssr', 'example'],
];
```

想让它成为新站点的默认外观，把 `default` 也改成 `example`。用户的个人选择存在 `users.skin`，
未登录访客用 `default`。

## 二、三个布局都得有

| 布局 | 谁用 |
|---|---|
| `default` | 所有「直出」页面：首页、登录、注册、文档、特殊页、插件页面 |
| `app` | 登录后的应用区（仪表盘、设置、消息），内容是 Vue 单页的挂载点 |
| `admin-app` | 后台，同样是 Vue 单页 |

**缺一个不会回退到核心**（核心没有布局），只会回退到**默认皮肤**的同名布局。如果这个皮肤自己就是默认皮肤，
那个页面直接 500。所以三个都写，哪怕 `app` / `admin-app` 只是照抄骨架。

## 三、皮肤能碰什么

| 能做 | 不能做 |
|---|---|
| 改布局、改配色、改排版 | 改路由、改 POST 处理逻辑 |
| 覆盖任意核心模板 | 绕过 CSRF |
| 覆盖插件模板，让插件跟着站点外观 | 假设插件一定装了 |
| 加自己的 CSS / JS | 把业务数据写进模板里算 |

皮肤模板是直接 `require` 的 PHP，**等于站点代码权限**。装第三方皮肤跟装第三方插件一样要谨慎。

## 四、第一次改动

改 `layouts/default.php` 里 `<header>` 那段就能看到效果，保存刷新即可，不用清缓存。

配色从 CSS 变量下手，别一处一处改颜色：

```css
:root {
    --color-bg: #fbfaf7;
    --color-bg-secondary: #f3f0e9;
    --color-text: #23262d;
    --color-border: #e5e1d8;
    --color-primary: #2f6b5f;
}
```

变量名以核心 `public/css/style.css` 里的为准（`--color-bg` / `--color-bg-secondary` / `--color-text` /
`--color-border` / `--color-primary` 等，完整列表见 [静态资源](assets.md)）。**别改核心 CSS 文件**，那是所有皮肤共用的。

## 五、验证

- 首页、`/login`、`/register`、`/w/*`（装了 Pages 才有）、`/special/version` 各打开一次。
- 登录后进 `/dashboard`、`/settings`——走的是 `app` 布局。
- 进 `/admin`——走的是 `admin-app` 布局。
- 手机上打开一次，导航和表格最容易崩。
- 切到阿拉伯语一次，看 RTL 有没有镜像错。

## 六、开工前先看这三篇

| 想看什么 | 去哪 |
|---|---|
| 布局里该调哪些钩子、有哪些变量 | [布局](layouts.md) |
| 覆盖模板的查找顺序 | [模板覆盖](templates.md) |
| CSS / JS 放哪、`?v=` 怎么换 | [静态资源](assets.md) |
| 发版和启用的规则 | [发布](release.md) |
