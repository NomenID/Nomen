# 自动更新

自动更新默认关闭。要启用，先给站点写一份 `config/autoupdate.php`（这个文件属于站点自己，自动更新和升级包都不会覆盖已存在的版本），再在后台「设置 → 自动更新」打开总开关。

> ⚠️ **有特殊需求时先关自动更新。** 比如站点有自定义目录、非标准数据库、额外的上传目录、特殊备份要求：先在后台关掉总开关，改好 `config/autoupdate.php`，跑 `php bin/nomen update check` 确认能读到发布源，再决定要不要重新打开，或者改更新流程。不要一边跑自动更新一边改配置。

## 更新源

`feed` 留空就等于这个站点不检查更新：

```php
// config/autoupdate.php
return [
    'feed' => 'https://nomenid.org/official-api/releases/latest',
    // ...
];
```

也可以用环境变量覆盖：

```env
NOMEN_UPDATE_FEED=https://nomenid.org/official-api/releases/latest
NOMEN_UPDATE_SCOPE=patch
```

`/api/version` 只报本站实际在跑的版本。官方发布信息由 `official` 插件在官方站提供 `/official-api/releases/latest`，它读官方站 `data/releases.json`；普通站点默认没有这个接口，只把 `feed` 指向官方地址即可（也可以指向任何同格式的静态 JSON）。官方站要装并启用 `official` 插件，普通站不需要。这个插件**不随核心发布包分发**，接口和返回文件在官方站自己的 `config/plugins/official.json` 里手写：

```json
{
    "base_path": "/official-api",
    "routes": {
        "releases/latest": "data/releases.json"
    }
}
```

发版时只改 `data/releases.json`，插件本身不用动。

## 允许自动更新的范围

`scope` 默认 `patch`：**只允许最后一位变动**（`1.7.0 → 1.7.1`）。

| 值 | 允许自动装 |
|---|---|
| `patch`（默认） | 只允许 `x.y.z → x.y.z+1` |
| `minor` | 第二位也允许（`1.7 → 1.8`） |
| `major` | 第一位也允许（`1.x → 2.0`） |

第二位 / 第一位变动默认只提示、不自动装；要放开就在配置里显式改 `scope`，或者设环境变量 `NOMEN_UPDATE_SCOPE`。这跟 WordPress「安全 / 小版本默认自动，大版本 opt-in」是同一个思路，只是默认更保守。

## 优先级

发布源给每次发布带一个 `priority`：

| 优先级 | 含义 | 行为 |
|---|---|---|
| `security` | 安全修复 | 绕过维护窗口，立即安装 |
| `critical` | 严重问题 | 绕过维护窗口，立即安装 |
| `normal`（默认） | 常规修复 | 在维护窗口内安装 |
| `feature` | 功能更新 | 默认只提示；`min_priority` 设成 `feature` 才自动装 |

`min_priority` 默认 `normal`，所以 `feature` 不会自动装。

## 每站自己的计划

都写在 `config/autoupdate.php`：

```php
'interval' => 43200,      // 每 12 小时检查一次（秒）
'check_at' => '03:30',    // 也可以改成每天固定时间；设置后优先于 interval
'window'   => '03:00-05:00', // 维护窗口；支持 23:00-02:00 跨午夜
'window_mode' => 'allow', // allow = 只在窗口内装（白名单）；deny = 窗口内不装（黑名单）
```

`window` 留空时两种模式都不限制安装时间（默认就是随时可装）。`window_mode` 默认 `allow`，
想反过来用就写 `deny`，例如 `window => '09:00-18:00', window_mode => 'deny'` 表示上班时间不装。

时间按 PHP 时区（`date_default_timezone_get()`）算。`security` / `critical` 不受窗口限制。

调度器本身每小时叫一次 `update.check`，由它决定这次该不该真的去查；没配 cron 就不会自动跑，后台会提示。

## 备份：备份失败就不更新

更新前会做两份备份，任何一份失败都直接中止，不动站点文件：

- **数据库**：调用 `php bin/nomen backup` 的逻辑（SQLite 用 `VACUUM INTO`，MySQL 用 `mysqldump`），文件落在 `data/backups/`，权限 `0600`。超时（默认 300 秒）也会中止。
- **文件**：`data/updates/backup-<旧版本>-<时间>.zip`，至少包含：
  - 会被覆盖 / 删除的程序文件；
  - 整个 `config/`（含 `installed.php`、`plugins.php`、`skins.php`、`autoupdate.php`）；
  - `.env`、`public/.user.ini`、`public/assets/brand/`；
  - `plugins/`、`skins/`、`templates/`；
  - 头像 / 证明材料 / 开发者图标（可在 `backup.uploads` 关掉）；
  - `data/oidc-key.json`、`data/build.json` 等。
- 备份包会重新打开做完整性校验，条目数和文件列表都要对得上；体积超过 `backup.max_mb`（默认 512 MB）就中止，避免把磁盘写满。
- 站点自己的额外文件用 `backup.extra` 加进来，用 `backup.exclude` 排除；不想被更新覆盖 / 删除的路径写进 `protect`。

**回滚只恢复程序文件。** 数据库迁移是增量的，不会自动回退；迁移失败时从 `data/backups/` 里更新前那份数据库备份恢复。备份里可能含密码哈希和密钥，不要放进 web root，也不要外传。数据库备份不会自动清理，定期手动清 `data/backups/`。

## 一次更新做了什么

1. 读 `config/autoupdate.php` 和发布源，比较版本、范围、优先级。
2. 下载发布包到 `data/updates/`，校验 SHA-256；没有可用哈希就拒绝。
3. 安全解包（拒绝 `..`、绝对路径、反斜杠、符号链接、超大包），核对包内版本。
4. 打文件备份、导出数据库；任何一步失败就停在这里。
5. 进入维护模式，访客看到 503；覆盖文件、跑核心和插件迁移、清缓存。
6. 退出维护模式，回访 `/api/version` 确认真新版本生效。
7. 成功：写更新清单、记活动日志、发成功邮件；失败：自动回滚文件、发失败邮件。

覆盖时会跳过站点自己的东西：`.env`、`config/installed.php`、`config/plugins.php`、`config/skins.php`、`config/plugins/`、`data/`、上传目录、`public/.user.ini`、`public/nomen-install/`；`vendor/`、`composer.*`、其他 `config/*`、`public/assets/brand/` 已存在就保留。`config/app.php` 会随新版覆盖；`config/autoupdate.php` 不会被覆盖。

## 日志与状态

都在 `data/updates/`：

| 文件 | 内容 |
|---|---|
| `state.json` | 上次检查、待安装版本、失败记录、停更提醒、最近 10 次结果 |
| `update.log` | 每一步的 JSON 行日志，超过 2 MB 转成 `.1` |
| `manifest.json` | 上一次自动更新装了哪些文件（下次据此清理旧文件） |
| `backup-*.zip` | 文件备份，默认保留最近 5 份 |
| `update.lock` | 同一时间只允许一个更新进程 |

后台「设置 → 自动更新」能展开看最近 60 行日志；CLI 用 `php bin/nomen update status`。

## 停更提醒

发布源可以带 `min_supported`。当前版本低于它时，后台设置卡和「站点健康」会提示，`data/updates/state.json` 里也会记一条 `eol`。要消除提醒只能升级到受支持的版本。

## 失败与重试

- **下载失败、哈希不对、解包失败、磁盘满**：瞬时失败，第一次不发邮件，一小时后自动再试；连续两次失败才发失败邮件。
- **备份失败 / 备份过大 / 数据库导出失败**：直接中止，不动文件。
- **覆盖失败、迁移失败、回访发现站点没起来**：自动解压备份回滚，发失败邮件。
- **回滚也失败**：发紧急邮件，后台保留失败记录并暂停同一版本的自动重试。
- 处理完可以在后台点「清除失败记录」，或执行 `php bin/nomen update reset`。

## 手动操作

```bash
php bin/nomen update status   # 看状态、上次结果、最近日志
php bin/nomen update check    # 只查版本，不安装
php bin/nomen update run      # 立即查并安装（需要后台已打开自动更新）
php bin/nomen update reset    # 清除失败记录
```

## 发布源格式

官方站 `data/releases.json`：

```json
{
  "version": "1.7.1",
  "priority": "normal",
  "released_at": "2026-11-20T00:00:00Z",
  "download": "https://dl.caellab.com/file/nomen/nomen-1.7.1.zip",
  "sha256": "<64 位>",
  "min_php": "8.1",
  "min_supported": "1.7.0",
  "notes": "https://nomenid.org/w/changelog"
}
```

`php code-scripts/build-dist.php --priority=security --min-supported=1.7.0` 会额外生成 `dist/release.json`，把这份文件放到官方站 `data/releases.json` 即可。也可以写成 `{"releases":[...]}` 列表，客户端取版本最高的一条。

## 两个环境注意点

- **cron 用 root 跑也可以**：覆盖出来的文件会按 `public/index.php` 的属主自动改回去，不会变成 root 属主。
- **opcache 关了时间戳校验时**（`opcache.validate_timestamps=0`），文件更新后 FPM 还在跑旧代码，回访会看到旧版本并触发回滚。要么打开时间戳校验（推荐），要么在更新后手动 reload PHP-FPM。

## 排错

- **后台提示未配置更新源**：`config/autoupdate.php` 的 `feed` 还是空的，或环境变量没生效。
- **一直「已是最新」但官方已经发版**：检查 feed 是否可达，或执行 `update check` 看具体错误。
- **收不到邮件**：后台「设置」里的邮件状态会显示是否配置；`update.log` 里也有 `email` 步骤。
- **更新后站点 503**：先看 `data/maintenance.json` 是否还在（正常更新结束会删掉，最多 15 分钟自动过期）；再看 PHP 错误日志和 `data/updates/update.log`。
- **想彻底关掉**：后台关总开关，或把 `feed` 清空；已经安装的版本不会回退。
