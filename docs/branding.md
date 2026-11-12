# 品牌与外观

站点名、图标、版权、政策链接这些都在 `config/brand.php`，环境变量优先。布局、登录页、邮件模板
都从这里取值，不要改模板里的字面量。

## 基本信息

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `name` | `BRAND_NAME` | `Nomen` | 站点名。登录页标题、邮件署名、`{:sitename}` 占位符都用它 |
| `base_url` | `APP_URL` | `http://localhost:8080` | 站点地址，不带末尾斜杠。OAuth 回调和邮件链接基于它 |
| `logo` | `BRAND_LOGO` | `/assets/brand/nomen-icon.svg` | 站点图标，布局左上角、favicon、页脚共用 |
| `copyright` | `BRAND_COPYRIGHT` | 空 | 页脚版权行，留空不显示 |
| `session_name` | `SESSION_NAME` | `NOMEN_SESSION` | 会话 cookie 名。同一个域名下跑多套 Nomen 时必须各不相同 |
| `support_name` | `BRAND_SUPPORT_NAME` | 空 | 支持团队名称，登录页和恢复页用；留空写「支持团队」 |
| `support_email` | `SUPPORT_EMAIL` | 空 | 对外支持邮箱，页面上给用户看的地址 |
| `admin_notify_email` | `ADMIN_NOTIFY_EMAIL` | 空 | 管理员通知收件人，和上面的对外邮箱分开 |

`support_email` 和 `admin_notify_email` 留空时，对应的邮件和入口不出现。建议两个都配：
一个是用户来信的地址，一个是站长自己收提醒的地址。

## 站点名与界面文案

内置界面文案里出现的品牌名都写成 `{:sitename}`，运行时自动替换成 `name`，调用方不用传参。
改 `BRAND_NAME` 之后，登录页的「登录 X」、邮件标题、通知前缀、页脚署名都会跟着变。

少数文案刻意保留 `Nomen`：GPL 许可正文、版本页的软件名、安装向导里指代软件本身的地方。
这些不是站点名，改品牌名不该动它们。

## 政策与附加条款

站点的政策写在 `policies` 里，最多三条；附加条款写在 `additional_terms` 里，条数不限：

```php
'policies' => [
    ['role' => 'privacy', 'label' => '', 'url' => 'https://example.com/privacy'],
    ['role' => 'terms', 'label' => '', 'url' => 'https://example.com/terms'],
    ['role' => 'other', 'label' => 'Community Guidelines', 'url' => 'https://example.com/rules'],
],
'additional_terms' => [
    ['label' => 'Trademark Notice', 'url' => 'https://example.com/trademark'],
],
```

- `role` 取 `privacy` / `terms` / `other`：注册页的两个勾选框分别取 `privacy` 和 `terms`，
  家长同意邮件里的隐私政策链接也取 `privacy`。
- `label` 留空时 `privacy` / `terms` 用现成的词典键；`other` 必须写 label。写词典键会按访客语言翻译，
  写别的语言原文就原样显示。
- `url` 留空的条目会被跳过。
- 认证页（登录、临时密钥登录）先列三条政策，再单独列附加条款。
- 后台「设置 → 政策」改过之后以数据库为准，这里的值只是默认值。
- 没配 `policies` 时回退到旧字段：`legal_links` 列表优先，其次 `privacy_url` / `terms_url` /
  `developer_guidelines_url`。

## 性别选项

注册、家庭页、账号恢复里的性别选项在 `config/genders.php`：

```php
return [
    ['value' => 'female', 'label' => 'Female'],
    ['value' => 'male', 'label' => 'Male'],
];
```

删掉不想要的条目即可；`value` 会存进数据库，改老站点的 value 会让已有资料对不上。
`label` 写词典键会按语言翻译，写中文原样显示。文件为空时用内置的四个选项。

## 页脚

页脚由 `Footer::html()` 渲染，顺序是：`config/footer.php`（存在时）→ 当前皮肤的
`partials/footer.php`。想完全自定义就复制 `config/footer.example.php` 成 `config/footer.php` 再改。

默认页脚的链接来自 `footer_links`：

```php
'footer_links' => [
    ['label' => '关于', 'url' => '/about'],
],
```

启用了 Pages 插件时，会自动追加一个「文档」入口，指向 Pages 的挂载路径。

`powered_by` 控制「Powered by Nomen」署名，默认 `true`。关掉完全没问题——`NOTICE` 是建议性文档，不附加条件。版本页我们只是建议保留。

建议保留的理由是版本页把程序、PHP、数据库、依赖库、插件和皮肤的署名摆在一起。**但插件和皮肤各自的许可证可能要求展示声明，装了什么就核对什么**，这跟 Nomen 无关。

**无 JS 时页脚链接直接指向 `/special/version`**，这是纯 HTML 里的版本页入口；保留署名的话别把 href 改掉。

## 首页

首页有三种模式，后台「首页」页切换，也可以直接改 `site_settings`：

| 模式 | 说明 |
|---|---|
| `default` | 内置落地页，标题、副标题、两个按钮、底部链接都可改 |
| `page` | 指定一个 Pages 页面当首页，填页面 slug |
| `template` | 用 `config/home-*.php` 里的自定义模板 |

默认模式可配的项：

| 键 | 说明 |
|---|---|
| `homepage.title` | 主标题，留空用默认 |
| `homepage.subtitle` | 副标题 |
| `homepage.primary_label` / `primary_url` | 主按钮文字与地址 |
| `homepage.secondary_label` / `secondary_url` | 次按钮 |
| `homepage.links_json` | 底部自定义链接，JSON 数组 |
| `homepage.hidden_links` | 隐藏的默认链接 id：`docs` / `open_platform` / `version` |

底部默认链接是「文档」（Pages 启用时）、「开放平台」（插件启用时）、「版本信息」。自定义链接排在默认
链接后面。插件通过 `homepage.links` 钩子加的条目带 `by` 字段，后台编辑器会标「由 X 托管」，
前台不标。

自定义模板：复制 `config/home.example.php` 成 `config/home-你的名字.php`，后台首页模式选 `template`
并填文件名。模板里能拿到 `$brand`，输出的是页面正文，外壳仍走当前皮肤。

默认首页的正文区块由 `homepage.sections` 钩子提供，没有插件返回内容时就只有标题、按钮和链接。

## 皮肤

默认皮肤在 `config/skins.php` 的 `default`，或环境变量 `DEFAULT_SKIN`。`enabled` 是允许使用的皮肤列表，
用户可以在设置页里换成列表里的任意一个。默认皮肤被禁用时会自动落到列表里第一个可用皮肤。

皮肤能覆盖模板，但盖不掉核心词典里的文案——要改「登录 X」这种字，改 `BRAND_NAME`，不是改皮肤。
皮肤的写法和目录结构见 [皮肤开发](skins.md)。
