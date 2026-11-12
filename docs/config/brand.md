# config/brand.php

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`config/brand.php`

品牌相关的兜底值：站点名、地址、支持邮箱、图标、页脚版权、法律链接。安装向导和后台「设置」写的是 `installed.php` 和数据库，这里的字面量只是初始值；在后台改过的不会回头覆盖这里。

共 16 项。

## 配置项

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `name` | `BRAND_NAME` | Nomen | — |
| `base_url` | `APP_URL` | / | — |
| `support_email` | `SUPPORT_EMAIL` | '' | — |
| `admin_notify_email` | `ADMIN_NOTIFY_EMAIL` | '' | — |
| `session_name` | `SESSION_NAME` | NOMEN_SESSION | — |

## 站点图标

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `logo` | `BRAND_LOGO` | /assets/brand/nomen-icon.svg | 布局左上角、favicon、页脚都用它。留空就用包里带的 Nomen 图标 |

## 页脚版权行

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `copyright` | `BRAND_COPYRIGHT` | '' | 站点所有者自己填，留空就不显示。要加链接写成 [文字](https://example.com) |
| `privacy_url` | `BRAND_PRIVACY_URL` | '' | 对外的政策页和开发者守则；留空时界面不显示对应链接 |
| `terms_url` | `BRAND_TERMS_URL` | '' | — |
| `developer_guidelines_url` | `BRAND_DEVELOPER_GUIDELINES_URL` | '' | — |
| `policies` | — | 空数组 | 站点政策，最多三条。role 取 privacy / terms / other，决定注册页勾选框和政策链接的用途； label 留空时 privacy / terms 用词典键，other 必须有 label；url 留空则该条不显示。后台「设置」改了以后以数据库为准，这里只是默认值。 |

## 附加条款

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `additional_terms` | — | 空数组 | 不限条数，单独一组展示，不占上面三条政策的名额。每项 ['label' => ..., 'url' => ...] |
| `legal_links` | — | 空数组 | 认证页（登录 / 临时密钥）法律声明的链接列表，每项 ['label' => ..., 'url' => ...]。 label 写什么显示什么；写 'Privacy Policy' 这类词典键会按当前语言翻译。留空时回退为 privacy_url / terms_url / developer_guidelines_url 按序合成。 |
| `support_name` | `BRAND_SUPPORT_NAME` | '' | 支持团队名称，登录页/恢复页用它；留空就写「支持团队」 |

## 页脚链接，按需加

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `footer_links` | — | 空数组 | ['label' => '隐私政策', 'url' => '/privacy'] Pages 插件启用时，Brand::footerLinks() 会自动补一个「文档」入口。 |

## 页脚署名，纯建议

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `powered_by` | — | true | 关掉不影响任何许可（NOTICE 是建议性文档）。保留的话它会链到 /special/version；无 JS 时 href 直接指版本页 |

