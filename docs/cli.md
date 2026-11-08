# 命令行

入口是 `php bin/nomen`，在项目根目录跑。

```bash
php bin/nomen help
```

## 命令

| 命令 | 作用 |
|---|---|
| `install` | 交互式安装：建库、导表、建管理员、写 `installed.php` |
| `upgrade` | 覆盖新版本文件之后跑：核心迁移 + 插件迁移，更新版本记录 |
| `migrate` | 只跑 `database/migrations/` 下没跑过的核心迁移 |
| `plugins list` | 列出插件、启用状态和插件注册的命令 |
| `plugins migrate` | 跑插件自己的 `database/migrations` |
| `plugins <命令>` | 跑插件注册的命令，例如 `plugins pages:sync` |
| `auth:reset` | 登录方式全关、进不去后台时恢复默认（全部开启） |

## 常见用法

### 安装

```bash
php bin/nomen install
```

会依次问数据库、站点信息、要启用的扩展、管理员账号。已经装过会拒绝，确实要重装加 `--force`。
用 root 跑时配置文件会自动改成目录属主，免得 PHP-FPM 读不到。

### 升级

```bash
php bin/nomen upgrade
```

先备份数据库和 `config/installed.php`、`data/`、`public/assets/avatars/`、`public/assets/recovery/`。
升级包不覆盖这些运行时文件。详见 [安装与升级](install.md)。

### 补文档页面

```bash
php bin/nomen plugins pages:sync
```

只补插件 `content/` 里新增的默认页面，不覆盖站内改过的内容。

### 恢复登录方式

后台把自己关在门外时：

```bash
php bin/nomen auth:reset
```

把五种登录方式全部设回开启。如果 `site_settings` 表还不存在，先跑 `upgrade`。

### 看插件状态

```bash
php bin/nomen plugins list
```

输出启用 / 停用的插件、版本、注册的命令。排查插件没加载时先看这里，再看 PHP 错误日志里
`requires` 不满足的记录。

## 定时任务与常驻进程

| 任务 | 命令 | 方式 |
|---|---|---|
| 通知邮件队列 | `php scripts/mail-drain.php` | systemd / cron，单实例（脚本自己加锁） |

邮件队列需要有人持续消费，否则站内通知不会变成邮件。脚本用 `scripts/mail-drain.lock` 防并发。

## 退出码

命令成功返回 `0`，失败返回非 `0` 并把原因写到 stderr。写脚本调用时按退出码判断，不要靠输出文本匹配。
