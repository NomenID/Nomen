# 自动更新

后台可以打开自动更新：定时任务每隔一段时间查一次官方发布源，有新版本就下载、校验、备份、覆盖文件、跑迁移，最后发一封邮件告诉管理员。默认关闭，需要站长自己打开。

## 前提

- 服务器上配了定时任务（`* * * * * php bin/nomen schedule run`），并且心跳正常。没有 cron 就不存在「自动」，只能手动执行 `php bin/nomen update run`。
- PHP 有 `zip` 和 `curl` 扩展（后台对应位置会直接提示）。
- Web 用户对站点根目录、`src/`、`public/`、`config/`、`data/` 有写权限。
- 想收邮件的话，SMTP 要配好（`config/email.php` / `.env`）。

## 打开

后台 →「设置」→「自动更新」：

| 选项 | 说明 |
|---|---|
| 启用自动更新 | 总开关。默认关闭。打开后下一次心跳会排一次检查。 |
| 更新通道 | `x.y.z` 只收修正版；`x.y` 收同一大版本；`all` 连新大版本也收。默认同一大版本。 |
| 通知邮箱 | 成功 / 失败各发一封。留空用「管理员地址」（`admin_notify_email`，再退回最早的管理员账号）。 |

保存后可以点「立即检查」马上查一次（只查，不装）。真正的安装在后台定时任务里进行，不需要人守着。

## 一次更新做了什么

1. 请求更新源（默认 `https://nomenid.org/api/version`），比较版本和通道。
2. 下载发布包到 `data/updates/`，校验 SHA-256；没有可用哈希就拒绝安装。
3. 安全解包（拒绝 `..`、绝对路径、符号链接、超大包），核对包内版本号。
4. 把会被覆盖 / 删除的文件打一份 zip 备份到 `data/updates/`。
5. 进入维护模式，访客看到 503；然后覆盖文件、跑核心和插件迁移、清缓存。
6. 退出维护模式，用 `https://站点/api/version?action=thissite` 回访一次，确认新版本真的生效。
7. 成功：写更新清单、记活动日志、发成功邮件；失败：自动回滚文件、发失败邮件。

## 哪些东西不会被覆盖

自动更新只动程序文件。下面这些属于站点自己，更新时会跳过：

```
.env
config/installed.php
config/plugins.php
config/skins.php
config/plugins/
data/
public/nomen-install/
public/assets/avatars/
public/assets/recovery/
public/assets/develops/
public/.user.ini
vendor/、composer.json、composer.lock     # 已存在时保留，站点自己的依赖不丢
config/ 下除 app.php 以外的文件           # 已存在时保留
public/assets/brand/                      # 已存在时保留，缺的补上
```

`config/app.php` 会被新版覆盖（版本号和新配置键都在里面）。如果改过它里面的默认值，升级前先把改动挪到 `.env` 或 `config/installed.php`，或者事后从备份里找回。

`vendor/`、`composer.json`、`composer.lock` 已存在时也会保留，站点自己的依赖不会丢。代价是新版如果增加了 composer 依赖，自动更新不会替你安装，需要手动跑一次 `composer require` / `composer update`。

## 日志与状态

都在站点的 `data/updates/`，不在 web root 里：

| 文件 | 内容 |
|---|---|
| `state.json` | 上次检查、待安装版本、失败记录、最近 10 次结果 |
| `update.log` | 每一步的 JSON 行日志，超过 2 MB 自动转成 `.1` |
| `manifest.json` | 上一次自动更新装了哪些文件（下一次据此清理旧文件） |
| `backup-*.zip` | 回滚备份，默认保留最近 2 份 |
| `update.lock` | 同一时间只允许一个更新进程 |

后台「设置 → 自动更新」里能展开看最近 60 行日志；命令行用 `php bin/nomen update status`。

## 两个环境注意点

- **cron 用 root 跑也可以**：覆盖出来的文件会按 `public/index.php` 的属主自动改回去，不会变成 root 属主。
  如果站点文件本来就不归 www 所有，先修好属主再用自动更新。
- **opcache 关了时间戳校验时**（`opcache.validate_timestamps=0`），更新写完了 FPM 还在跑旧代码，
  回访会看到旧版本并触发回滚。要么把时间戳校验打开（推荐），要么在更新后手动 reload PHP-FPM。
  这也是为什么自动更新默认关闭：先确认环境再开。

## 失败与重试
- **下载失败、哈希不对、解包失败、磁盘满**：算瞬时失败，第一次不发邮件，一小时后自动再试；连续两次失败才发失败邮件。
- **覆盖失败、迁移失败、回访发现站点没起来**：自动解压备份回滚，发失败邮件；站点回到更新前的版本。
- **回滚也失败**：发紧急邮件，后台保留失败记录并暂停同一版本的自动重试，等人处理。
- 回滚只恢复程序文件；数据库迁移是增量的，不会自动回退。迁移失败时先看 `update.log` 和错误日志，
  必要时从升级前的数据库备份恢复。

处理完可以在后台点「清除失败记录」，或执行 `php bin/nomen update reset`。

## 手动操作

```bash
php bin/nomen update status   # 看状态、上次结果、最近日志
php bin/nomen update check    # 只查版本，不安装
php bin/nomen update run      # 立即查并安装（需要后台已打开自动更新）
php bin/nomen update reset    # 清除失败记录
```

也可以用调度器把检查排到下一分钟：

```bash
php bin/nomen schedule list
```

## 排错

- **后台提示不可写**：看 `data/`、`src/`、`public/`、`config/` 的属主和权限；宝塔上一般是 `www:www` + 目录 755 / 文件 644。
- **一直「已是最新」但官方已经发版**：检查 `updates.source` 是否可达，或执行 `update check` 看具体错误。
- **收不到邮件**：后台「设置」里的邮件状态会显示是否配置；`update.log` 里也有 `email` 步骤。
- **更新后站点 503**：先看 `data/maintenance.json` 是否还在（正常更新结束会删掉，最多 15 分钟自动过期）；再看 PHP 错误日志和 `data/updates/update.log`。
- **想彻底关掉**：后台关总开关即可；已经安装的版本不会回退。
