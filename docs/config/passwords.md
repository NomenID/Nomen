# config/passwords.php

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`config/passwords.php`

密码哈希。默认按主机能力自动选 Argon2id，PHP 没有 Argon2 或 `memory_limit` 放不下时自动改用 bcrypt。改动只影响以后写入的新哈希；已有哈希在用户下次登录时按 `upgrade` 自动改写。

共 3 项。

## 配置项

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `algorithm` | — | auto | auto（默认，有 Argon2id 且内存够就用它）/ argon2id / bcrypt |
| `options` | — | 空数组 | 传给 password_hash 的选项；留空用 PHP 默认（argon2id 64 MiB / 4 次 / 1 线程，bcrypt cost 12） |
| `upgrade` | — | on_login | on_login（默认，登录成功时把老算法 / 老参数的哈希就地改写）/ off（不改写，等用户自己改密码） |

