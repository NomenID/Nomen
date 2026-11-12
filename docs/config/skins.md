# config/skins.php

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`config/skins.php`

皮肤：站点默认用哪个、允许用户选哪些。用户自己的选择存在 `users.skin`，未登录设备用 `default`。

共 2 项。

## 配置项

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `default` | `DEFAULT_SKIN` | nomen-2026 | 站点默认皮肤（环境变量 DEFAULT_SKIN 优先） |
| `enabled` | — | nomen-ssr | 站点启用的皮肤。站长想禁用某个，从列表里删掉即可；整个键不写表示不限制。 |

