# 自动更新

自动更新默认开启：更新源指向官方发布源，后台总开关默认打开，后台调度器跑起来后每 12 小时检查一次。默认只自动装最后一位（patch）版本，第二位 / 第一位只提示。`config/autoupdate.php` 属于站点自己，自动更新和升级包都不会覆盖已存在的版本。

> ⚠️ **有特殊需求时先关自动更新。** 比如站点有自定义目录、非标准数据库、额外的上传目录、特殊备份要求：先在后台关掉总开关，改好 `config/autoupdate.php`，跑 `php bin/nomen update check` 确认能读到发布源，再决定要不要重新打开，或者改更新流程。不要一边跑自动更新一边改配置。

## 更新源

`feed` 留空就用官方发布源；填 `'off'` 才是完全不检查更新：

```php
// config/autoupdate.php
return [
    'feed' => 'off', // 关掉更新检查；删掉这一项或留空则用官方源
    // ...
];
```

升级前的老站点如果文件里还是 `'feed' => ''`，升级后也会按官方源检查，不需要手工改。

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

发布源可以带 `min_supported`（对所有分支生效）和 `support`（按分支标状态）。当前版本低于 `min_supported`，或者它所在的分支被标成 `eol`，后台设置卡和「站点健康」都会提示，`data/updates/state.json` 里记一条 `eol`。

停更分支不是直接甩到最新版：

- 本分支还有更高的版本（通常是最后一版补丁）→ 先把那一版装上，装完下一次检查才提示跨分支。
- 跨分支的升级**永远不自动装**，后台会给一条建议路径，比如 `1.7.3 → 1.8.5`，由管理员点「立即更新」或跑 `php bin/nomen update run`。

## 失败与重试

- **下载失败、哈希不对、解包失败、磁盘满**：瞬时失败，第一次不发邮件，一小时后自动再试；连续两次失败才发失败邮件。
- **备份失败 / 备份过大 / 数据库导出失败**：直接中止，不动文件。
- **覆盖失败、迁移失败、回访发现站点没起来**：自动解压备份回滚，发失败邮件。
- **回滚也失败**：发紧急邮件，后台保留失败记录并暂停同一版本的自动重试。
- 处理完可以在后台点「清除失败记录」，或执行 `php bin/nomen update reset`。

## 手动操作

后台「设置 → 自动更新」发现新版本后会显示「立即更新到 x.y.z」。点它只是**排队**，由调度器在下一分钟执行（核心任务 `update.manual`），页面会轮询进度。管理员手动安装会绕过自动开关、`scope`、`min_priority` 和维护窗口，但仍然走 preflight、文件 / 数据库备份和失败记忆；上次失败过的版本要先「清除失败记录」。排队请求 10 分钟没被调度器捡起来就作废。

```bash
php bin/nomen update status   # 看状态、上次结果、最近日志
php bin/nomen update check    # 只查版本，不安装
php bin/nomen update run      # 立即查并安装（仍受自动开关、scope、优先级、窗口限制）
php bin/nomen update reset    # 清除失败记录
```

## PHP 没有 CA / 服务器连不上更新源

有些 PHP 环境没装 CA 证书，服务器自己请求发布源会失败（`update.log` 里是 `更新源不可达`）。后台「设置 → 自动更新」的「用浏览器检查」可以让浏览器直接读发布源：只发一个 GET，把版本号显示在页面上。**结果只留在页面内存里，不向本站写回任何内容，也不会启用「立即更新」**——安装仍然需要服务器能访问更新源。要根治就修 PHP 的 CA：更新系统的 `ca-certificates`，或在 `php.ini` 里配 `openssl.cafile` / `curl.cainfo`。

发布源（官方站的 `official` 插件）对这条 GET 返回 `Access-Control-Allow-Origin: *`；第三方发布源也要允许跨域，浏览器才读得到。

## 发布源格式

两种形状都认，客户端看 `support` 在不在决定用哪套规则。

**单条**（老格式，只有一个「最新版本」）：

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

**按分支列**（同时维护多个分支时用）：

```json
{
  "version": "1.9.0",
  "min_supported": "1.8.0",
  "support": {
    "1.9": { "status": "active" },
    "1.8": { "status": "maintenance", "until": "2027-03-01" },
    "1.7": { "status": "eol", "last": "1.7.3" }
  },
  "releases": [
    { "version": "1.9.0", "branch": "1.9", "download": "https://dl.caellab.com/file/nomen/nomen-1.9.0.zip", "sha256": "<64 位>", "priority": "feature" },
    { "version": "1.8.5", "branch": "1.8", "download": "https://dl.caellab.com/file/nomen/nomen-1.8.5.zip", "sha256": "<64 位>", "priority": "security" }
  ]
}
```

`status` 只有三个值：`active`（正常发版）、`maintenance`（只收安全修复）、`eol`（停止支持，可带 `last` 指向最后一版）。没列出来的分支按 `eol` 处理。

客户端怎么挑：

| 当前情况 | 装哪个 | 会不会自动装 |
|---|---|---|
| 本分支有更高版本 | 本分支最高 | 按 `scope` 判断（默认只装最后一位） |
| 分支 `active` / `maintenance`，没有更高版本 | 不动 | — |
| 分支 `eol` 或不在 `support` 里 | 最近的高一级受支持分支，附升级路径 | **不会**，只提示 |
| 没有 `support` 表（老格式） | 全局最高版本 | 按 `scope` 判断 |

发版时：`php code-scripts/build-dist.php --priority=security --branch=1.8` 生成的 `dist/release.json` 会带 `branch` 字段；把这一条**追加**到官方站 `data/releases.json` 的 `releases` 数组里，别覆盖整份。分支从 `maintenance` 转 `eol` 时手改一次 `support`。

老客户端（1.9.0 及更早）读列表时只取版本最高的一条，行为和单条格式一样，不会被新格式带崩。

## 两个环境注意点

- **cron 用 root 跑也可以**：覆盖出来的文件会按 `public/index.php` 的属主自动改回去，不会变成 root 属主。
- **opcache 关了时间戳校验时**（`opcache.validate_timestamps=0`），文件更新后 FPM 还在跑旧代码，回访会看到旧版本并触发回滚。要么打开时间戳校验（推荐），要么在更新后手动 reload PHP-FPM。

## 排错

- **后台提示更新检查已关闭**：`config/autoupdate.php` 的 `feed` 被设成了 `'off'`，或填的地址不是 http(s) 地址。改回官方地址（或删掉 `feed` 这一项）即可。
- **一直「已是最新」但官方已经发版**：检查 feed 是否可达，或执行 `update check` 看具体错误。
- **服务器报「更新源不可达」但浏览器能打开**：PHP 缺 CA，先修 CA；临时可以用后台「用浏览器检查」看版本，但它只能提示，不能安装。
- **收不到邮件**：后台「设置」里的邮件状态会显示是否配置；`update.log` 里也有 `email` 步骤。
- **更新后站点 503**：先看 `data/maintenance.json` 是否还在（正常更新结束会删掉，最多 15 分钟自动过期）；再看 PHP 错误日志和 `data/updates/update.log`。
- **想彻底关掉**：后台关总开关，或把 `feed` 设成 `'off'`；已经安装的版本不会回退。
