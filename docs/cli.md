# 命令行

`php bin/nomen` 是唯一的入口。所有命令都在站点根目录跑，需要能读到 `config/installed.php`。

```bash
php bin/nomen help
```

## 全部命令

| 命令 | 作用 |
|---|---|
| `install` | 命令行安装（交互式） |
| `upgrade` | 跑核心迁移、补 UNID、更新版本记录 |
| `migrate` | 单独跑核心迁移（不更新版本号） |
| `doctor` | 体检：数据库、目录权限、pepper、扩展、皮肤/插件目录 |
| `plugins list` | 列出插件三态和它们注册的命令 |
| `plugins migrate [slug]` | 跑插件的迁移 |
| `plugins <命令>` | 跑插件注册的命令（如 `pages:import`） |
| `user:passwd <用户>` | 改密码 |
| `user:promote <用户>` | 设为管理员 |
| `user:demote <用户>` | 取消管理员 |
| `auth:reset` | 把五种登录方式全部恢复开启（关到没路可走时的兜底） |
| `backup [目录]` | 备份数据库 |
| `restore <文件> [--yes]` | 恢复数据库 |

## 常用场景

### 装完先体检

```bash
php bin/nomen doctor
```

会报：数据库能不能连、目录权限、pepper 是不是默认值、可选扩展、皮肤和插件目录是否可读。
**换机器、升级后、出怪事之前都先跑一次。**

### 管理员把自己锁在门外

```bash
# 密码忘了但有 SSH
php bin/nomen user:passwd yunyun

# 权限没了
php bin/nomen user:promote yunyun

# 五种登录方式全被关掉了
php bin/nomen auth:reset
```

`user:passwd` 会提示输入新密码，不回显。

### 升级

```bash
php bin/nomen upgrade
php bin/nomen plugins migrate
```

`upgrade` 是幂等的：跑过的迁移不会重跑，UNID 只补空的。

### 插件命令

```bash
php bin/nomen plugins list                       # 先看有哪些
php bin/nomen plugins pages:sync
php bin/nomen plugins pages:import docs --prefix=local-docs --update
php bin/nomen plugins search:reindex --fresh
```

### 备份

```bash
php bin/nomen backup /tmp
php bin/nomen restore /tmp/nomen-20261113-101500.sql --yes
```

只处理数据库。文件（`config/installed.php`、`.env`、`data/`、上传目录）要另外备，
见 [备份与恢复](integrate/backup-restore.md)。

## 退出码

| 码 | 含义 |
|---|---|
| 0 | 成功 |
| 1 | 失败（看 stderr） |

脚本里用 `&&` 串起来之前先确认上一条真的返回 0——`doctor` 发现问题时也是 1。

## 在 CI / 容器里

```bash
php bin/nomen upgrade --yes     # 非交互
php bin/nomen doctor || exit 1
```

`install` 是交互式的，容器里用环境变量 + 安装器写好的 `installed.php` 更省事。

## 坑

- **在错误的目录跑**：`bin/nomen` 会按 `dirname(__DIR__)` 找配置，从别处 `php /path/bin/nomen` 也能跑，
  但要保证 `config/` 在它旁边。
- **`upgrade` 之前没备份**：它改表结构，出问题只能靠备份。
- **`restore` 不确认就覆盖**：默认会问，`--yes` 跳过；脚本里用 `--yes` 要格外小心。
- **CLI 和 FPM 用的 PHP 不是同一个**：扩展、版本可能不一样。用 `which php` 和面板里 FPM 的版本对一下。

## 检查清单

- [ ] 装完跑过 `doctor`
- [ ] 升级前备过份
- [ ] 脚本里检查了退出码
- [ ] `user:passwd` 之后能登录
