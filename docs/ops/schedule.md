# 定时任务

服务器上一条 crontab 每分钟叫一次核心，具体跑什么由每个任务自己声明的间隔决定。

## 配

crontab 加一行，路径换成站点根目录：

```bash
* * * * * php /www/wwwroot/example.org/bin/nomen schedule run >/dev/null 2>&1
```

宝塔：计划任务 → Shell 脚本 → 每分钟一次，命令填 `php /www/wwwroot/.../bin/nomen schedule run`。
面板里选的 PHP 版本要和站点一致；命令行 `php` 不在 PATH 里时写完整路径
（`/www/server/php/83/bin/php`）。

配完等一分钟，后台「设置 → 定时任务」能看到心跳时间；没跑起来会显示上次心跳是几点。

## 不配会怎样

站点照常工作。没有 cron 时任务不按点跑：Pages 的 sitemap 回到请求时现算，插件注册的任务要站长
在后台点「立即运行」。功能不会因为缺 cron 而消失，只是不新鲜。

## 任务从哪来

- 核心：`core.tick`，每分钟一次，本身什么都不做 —— 它的 `last_run_at` 就是 cron 的健康信号。
- 核心：`update.check`，每小时被叫一次，但真正要不要查由 [自动更新](auto-update.md) 的
  `updates.interval` 决定（默认 12 小时一次）；后台开关关着时只查不装。
- 插件：注册 `schedule.tasks` 钩子，见 [钩子参考](../hooks/index.md)。

任务 id 带点（`pages.sitemap`），同 id 先注册的生效，核心的不会被插件顶掉。

## 看状态 / 手动跑

```bash
php bin/nomen schedule list
```

会列出每个任务的间隔、上次 / 下次时间、失败次数，以及要加的那行 crontab。
后台「设置 → 定时任务」是同一份数据；「立即运行」只是把任务排到下一次心跳，不在网页请求里执行。

## 行为

- 跑之前先在 `scheduled_tasks` 里占一行锁，最多占 10 分钟；同一分钟起了两个进程也只有一个会执行。
- 一次 `schedule run` 最多跑 45 秒，剩下的任务留给下一分钟（`NOMEN_SCHEDULE_BUDGET` 可改）。
- 任务失败只记 `last_error` 和失败次数，不自动停用，也不会让站点报错。
- 错过的任务不补跑：服务停了一天再起来，任务只跑一次，然后按自己的间隔重新计时。
- `daily_at` 按 PHP 时区算（`date_default_timezone_get()`），不是 UTC。

## 表

`scheduled_tasks`，`php bin/nomen upgrade` 时建。时间列都是 Unix 时间戳。
要临时停掉一个任务，把它的 `next_run_at` 改到很远（比如一年后）—— 心跳只补缺行、不改这个值；
删掉整行则下一次心跳会重新建，按“立即到期”处理。

## 和邮件队列的关系

通知邮件的补发还是 `scripts/mail-drain.php` 常驻进程（见 `INSTALL.md` 的「邮件」一节），
不在这个调度器里。
