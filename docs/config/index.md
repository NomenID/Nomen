# 配置参考

每个配置文件一页。读配置时的优先级是 **`.env` → `config/installed.php` → 文件里的字面量**，表里的「默认值」指的是最后那一档；「环境变量」列有值的，说明 `.env` 可以覆盖。

| 文件 | 配置项 |
|---|---|
| [app.php](app.md) | 50 |
| [brand.php](brand.md) | 16 |
| [consent-age.php](consent-age.md) | 46 |
| [email-policy.php](email-policy.md) | 11 |
| [email.php](email.md) | 7 |
| [identity-providers.php](identity-providers.md) | 56 |
| [plugins.php](plugins.md) | 1 |
| [skins.php](skins.md) | 2 |

数据类文件（`countries.php` / `genders.php` / `consent-age-sources.php`）是列表常量，不是可调项，直接在 `config/` 里看；`home-nomenid.php` 只给官方站用，不进发布包。
