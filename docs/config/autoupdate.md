# config/autoupdate.php

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`config/autoupdate.php`

共 22 项。

## 配置项

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `feed` | `NOMEN_UPDATE_FEED` | '' | 发布源；空 = 关闭更新检查。官方站由 official 插件提供下面这个地址，普通站点不装 official，只把 feed 指过去拉取。 |
| `download_base` | `NOMEN_UPDATE_DOWNLOAD_BASE` | https://dl.caellab.com/file/nomen/ | 发布包所在目录；feed 没给 download 时用它拼 nomen-<版本>.zip |
| `min_php` | — | 8.1 | 新版本要求的最低 PHP；低于它不自动更新 |

## 允许自动更新的范围

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `scope` | `NOMEN_UPDATE_SCOPE` | patch | patch = 只更最后一位（默认）；minor = 第二位也允许；major = 都允许。可以用环境变量 NOMEN_UPDATE_SCOPE 覆盖。 |
| `interval` | — | 43200 | 两次检查之间的最短间隔（秒）；check_at 非空时改按每天固定时间检查 |
| `check_at` | — | '' | 每天固定检查时间（HH:MM）；留空则按 interval |
| `window` | — | '' | 维护窗口（HH:MM-HH:MM）；security/critical 会绕过窗口。支持跨午夜，例如 23:00-02:00。留空 = 不限时间。 |

## 窗口怎么用

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `window_mode` | — | allow | allow = 白名单，只在窗口内安装（默认）； deny = 黑名单，窗口内不安装，其余时间都可以。 |

## 低于这个优先级只提示、不自动装

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `min_priority` | — | normal | security > critical > normal > feature |
| `notify_email` | — | '' | 通知邮箱；留空用后台设置，再退回 admin_notify_email / 最早的管理员账号 |

## 备份

任何一步备份失败或超出上限，更新直接中止，不动站点文件。

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `backup.database` | — | true | 更新前用 php bin/nomen backup 的逻辑导出数据库（SQLite 用 VACUUM INTO）。关掉之前先确认你有外部数据库备份；这里关了自动更新就不会再留数据库快照。 |
| `backup.timeout` | — | 300 | 数据库导出超时（秒）；超时就中止更新 |
| `backup.uploads` | — | true | 头像 / 证明材料 / 开发者图标也打进文件备份；站点很大时可关掉并自行备份 |
| `backup.max_mb` | — | 512 | 单次文件备份的体积上限（MB）；超了就中止，避免把磁盘写满 |
| `backup.keep` | — | 5 | 保留最近几份文件备份 |
| `backup.extra` | — | 空数组 | 额外要备份的路径（相对站点根），文件或目录都行 |
| `backup.exclude` | — | 空数组 | 备份时排除的路径前缀（相对站点根） |

## 其他

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `protect` | — | 空数组 | 永远不覆盖 / 不删除的额外路径（相对站点根）；目录以 / 结尾 |
| `max_package_mb` | — | 200 | 更新包体积上限（MB） |
| `min_free_mb` | — | 100 | 更新前要求的最小剩余空间（MB） |
| `maintenance_ttl` | — | 900 | 维护页最多挂多久（秒）；进程中途被杀也会自动过期 |
| `lock_ttl` | — | 900 | 更新锁 / 中断判定的超时（秒） |

