# 品牌与外观

站点名、图标、版权、法律链接、页脚、首页，都从两处来：`config/brand.php` 的默认值，和后台「设置」里的实际值。
**布局和模板里不要写死这些东西**，否则换站名要改一堆文件。

## brand.php 全部键

| 键 | 环境变量 | 说明 |
|---|---|---|
| `name` | `BRAND_NAME` | 站点名。标题、页头、页脚、邮件主题前缀都用它 |
| `base_url` | `APP_URL` | 绝对地址（`https://example.com`，末尾不带斜杠） |
| `support_email` | `SUPPORT_EMAIL` | 对外支持邮箱，页面上显示 |
| `admin_notify_email` | `ADMIN_NOTIFY_EMAIL` | 管理员工单提醒的收件人，和上面分开 |
| `session_name` | `SESSION_NAME` | 会话 cookie 名 |
| `logo` | `BRAND_LOGO` | 站点图标，页头 / favicon / 页脚都用；留空用包内图标 |
| `copyright` | `BRAND_COPYRIGHT` | 页脚版权行，留空不显示 |
| `privacy_url` / `terms_url` / `developer_guidelines_url` | 各自环境变量 | 政策页，留空不显示 |
| `policies` | — | 最多三条政策，`role` 取 `privacy` / `terms` / `other` |
| `additional_terms` | — | 附加条款，不限条数，单独一组展示 |
| `legal_links` | — | 认证页的法律声明链接列表 |
| `support_name` | `BRAND_SUPPORT_NAME` | 支持团队名，留空写「支持团队」 |
| `footer_links` | — | 页脚链接数组 |
| `powered_by` | — | 页脚 `Powered by Nomen`，`false` 关掉 |

## 版权行可以带链接

```php
'copyright' => '© 2026 [虚舟实验室](https://www.caellab.com)',
```

`[文字](链接)` 会被渲染成 `<a>`，其余部分转义。只支持这一种语法，别塞 HTML。

## 三条法律链接的区别

| 键 | 出现在哪 | 条数 |
|---|---|---|
| `policies` | 注册页勾选框、页脚 | 最多 3 条，`role` 决定用途 |
| `additional_terms` | 单独一组 | 不限 |
| `legal_links` | 登录页 / 临时密钥页底部 | 不限，留空回退到 `privacy_url` 那三条 |

`policies` 的 `label` 留空时，`privacy` / `terms` 用词典键（会翻译），`other` 必须自己写 label。

```php
'policies' => [
    ['role' => 'privacy', 'url' => 'https://example.com/privacy'],
    ['role' => 'terms',   'url' => 'https://example.com/terms'],
    ['role' => 'other',   'label' => 'Community rules', 'url' => 'https://example.com/rules'],
],
```

**后台改过的以数据库为准**，`brand.php` 只是默认值。

## 图标

两条路：

1. 后台「设置」上传（135×135，2MB 上限，落 `public/assets/develops/`）。
2. 直接给地址：`BRAND_LOGO=/assets/brand/icon.svg`。

服务器缺 GD 或目录不可写时，上传会自动降级成「填链接」。**favicon 用的也是这个文件**，
所以它最好是个方形的 SVG 或 PNG。

## 首页三种模式

后台「首页」里选：

| 模式 | 渲染 | 什么时候用 |
|---|---|---|
| 默认 | `templates/home.php` | 大多数站点 |
| 指定 Pages 页面 | `templates/home-page.php` | 首页就是一篇文档 |
| 自定义模板 | `templates/home-custom.php` + `config/home-*.php` | 要完全自定义版式 |

自定义模板的例子（`config/home-example.php`）：

```php
<?php
return [
    'title'    => 'Example',
    'subtitle' => '一段话说明这是干什么的',
    'sections' => [
        '<section class="hero">...</section>',
    ],
];
```

模板文件返回的数组里，`sections` 是 HTML 片段数组。**这里的 HTML 不会被转义**，所以只能写自己控制的字符串。

## 首页的标题 / 副标题 / 按钮

默认首页的这几样从后台读，留空时回退成词典里的默认值：

| 设置项 | 留空时 |
|---|---|
| 标题 | 站点名 |
| 副标题 | 「自由的账号星系」（按语言翻译） |
| 主按钮 | 「下载」，指向 `/w/download` |
| 次按钮 | 「演示」，指向 `/login` |

底部那行链接（文档 / 版本信息 / GitHub）可以单独隐藏，也能加自定义链接。

## 页脚

统一走 `Footer::render()`：先找 `config/footer.php`，没有就用当前皮肤的 `partials/footer.php`。

```bash
cp config/footer.example.php config/footer.php
```

片段里能用 `$brand` / `$links` / `$layout`。插件用 `footer.links` 加链接，`footer.html` 改最终 HTML。

`config/footer.php` **不进 git**（里面有站点自己的东西），升级时也不会被覆盖。

## 品牌前缀的其他地方

`Brand` 的取值还被这些地方用：

- 邮件主题前缀（`EmailTemplate::subjectPrefix()`）
- TOTP 的 issuer（验证器里显示的名字）
- WebAuthn 的 RP name（生物识别弹窗里的名字）
- 站内通知的标题前缀

改站名之后这些都跟着变，不用逐个改。

## 坑

- **`base_url` 末尾带斜杠**：拼出来的链接会变成 `//login`，OAuth 回调也对不上。
- **后台改了站点名，邮件还是旧名字**：邮件前缀读的是同一份品牌配置，检查 `installed.php` 里的 `app.name`。
- **版权行写了 HTML**：只支持 `[文字](链接)`，`<br>` 之类会原样显示成文字。
- **`powered_by` 关掉之后**：页脚不再有版本页入口，纯 HTML 里就没有通往 `/special/version` 的链接了。

## 检查清单

- [ ] 站点名、`base_url`、支持邮箱都填了
- [ ] 图标是方形的，favicon 显示正常
- [ ] 法律链接点开都是真页面
- [ ] 首页三种模式各切一次看过
- [ ] 页脚版权行和链接对
- [ ] 手机上打开看过
