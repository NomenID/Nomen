# nomen-2026（默认皮肤）

星野背景 + 卡片式认证页。默认皮肤，新装站开箱就是这个。

## 目录

```
nomen-2026/
├── skin.json
├── layouts/
│   ├── default.php        普通页面：页头导航、星野背景、页脚、移动端抽屉
│   ├── app.php            应用区：只出挂载点 + Vite 入口
│   └── admin-app.php      后台：同上，换 admin 入口
├── partials/footer.php
├── templates/landing.php  落地页模板（覆盖核心同名模板）
└── i18n/
```

## 特点

- **星野背景**：`require Skins::template('_site_sky')` 引入核心片段，铺在页面底层。
  不想要就覆盖 `_site_sky` 成空文件——这也是「皮肤能覆盖公共片段」的示例。
- **深浅色**：`data-theme` 挂在 `<html>` 上，`style.css` 里两套变量。首屏有内联脚本防闪。
- **卡片式认证页**：登录 / 注册 / 两步验证都是居中卡片，样式来自核心 CSS，皮肤只负责布局。
- **移动端抽屉**：`_mobile_menu` 片段，登录后带通知角标。

## 用户能看到什么

- 首页：标题 + 副标题 + 两个按钮 + 正文区块（区块来自 `homepage.sections` 钩子）。
- 头部：logo、导航（`Nav::items()`）、语言选择器（允许切换时）、主题切换、通知图标。
- 页脚：版权行（支持 `[文字](链接)`）、政策链接、`Powered by Nomen`。

## 定制建议

| 想改 | 改哪 |
|---|---|
| 配色 | 覆盖 CSS 变量 `:root` 和 `[data-theme="light"]` 两处 |
| 首页文案 / 按钮 | 后台「首页」，不用碰皮肤 |
| 去掉星空 | 覆盖 `_site_sky` 模板 |
| 页脚 | 复制 `config/footer.example.php` 成 `config/footer.php` |
| 头部导航项 | `nav.items` 钩子，别改布局 |

## 坑

- **别把 `landing.php` 和 `home.php` 搞混**：前者是这个皮肤自带的落地页模板，后者是核心的默认首页。
  后台把首页切成「默认模式」时用的是 `home.php`。
- **`app.php` / `admin-app.php` 不要加 PHP 页头**：Vue 组件自己画导航，加了会叠两层。
- **改完 CSS 记得换 `?v=`**：`style.css` 的版本号在布局里写死，改了文件不换版本，浏览器和 CDN 还拿旧的。

## 源码

<https://github.com/NomenID/skin-nomen-2026>（Apache-2.0）
