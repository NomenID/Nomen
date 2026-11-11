# 安装与升级

完整的安装步骤、数据库准备和 nginx 配置在仓库根目录的 [INSTALL.md](https://github.com/NomenID/Nomen/blob/main/INSTALL.md)。这一页只列文档集里需要反复用到的部分。

## 环境要求

| 项 | 要求 |
|---|---|
| PHP | 8.1 及以上 |
| 扩展 | PDO、mbstring、json、openssl；SQLite 需要 pdo_sqlite，MySQL 需要 pdo_mysql |
| 数据库 | MySQL 8 / MariaDB 10.6+ / SQLite 3.35+ |
| Web 服务器 | nginx 或 Apache，web root 必须指向 `public/` |
| 可选 | Redis（限流、跨设备退出登录）、`geoip2/geoip2`（GeoIP 插件） |

PHP 依赖已经在发布包的 `vendor/` 里，不用在服务器上跑 Composer。从源码部署才需要 `composer install`。

## 必须做对的三件事

1. **web root 指到 `public/`**。指到项目根会看到根目录的 `index.html` 提示页，那不是站点。
2. **伪静态**：`try_files $uri $uri/ /index.php$is_args$args;`。`$uri/` 不能省，否则目录地址 404。
   装之前可以访问 `/__rewrite_probe`，返回 `ok` 就对了。
   加不了伪静态也能装：向导里把「短地址」关掉，地址会变成 `index.php?page=login` 这种，功能一样。
   开关是 `config/installed.php` 的 `app.pretty_urls`（`false` = 查询串模式；**没写这一项按短地址处理**）。
3. **`public/nomen-install/` 装完删掉或改名**。留着等于把安装入口开放给所有人。

`config/`、`data/`、`public/assets/avatars/`、`public/assets/recovery/`、`public/assets/develops/`
需要 PHP 进程可写。发布包解出来的文件权限可能是 666，部署后统一成文件 644、目录 755。

## 安装方式

网页向导：浏览器打开站点，会跳到 `/nomen-install/`，七步走完（环境 → 数据库 → 站点 → 管理员 → 扩展 → 邮件 → 确认）。

命令行：`php bin/nomen install`，问的东西一样。已经装过会拒绝，要重装加 `--force`。

两种方式最终都生成 `config/installed.php`，里面是数据库口令、pepper 和站点基本信息。
**这个文件不要提交到任何仓库，也不要外传。**

## 升级

### 升级前备份

升级只覆盖程序文件，不碰运行期数据，但备份还是先做一份。在站点根目录执行：

```bash
tar czf /tmp/nomen-backup-$(date +%F).tar.gz --ignore-failed-read \
  config data database public/assets/avatars public/assets/recovery public/assets/develops \
  skins plugins
mysqldump -u <数据库用户> -p <数据库名> > /tmp/nomen-backup-$(date +%F).sql
```

- `config/`：`installed.php` 是站点配置和密钥，`brand.php`、`plugins.php`、`skins.php`、`plugins/*.json` 是站点定制。
- `data/`：上传的文件、GeoIP 数据。`database/` 只在用 SQLite 时有用，库文件默认是 `database/nomen.sqlite`。
- `public/assets/{avatars,recovery,develops}/`：头像、恢复工单附件、站点和应用图标。这三个目录不在发布包里，被 `rsync --delete` 带上就会整个删掉，删了找不回来。
- 装了非随包的插件或皮肤，`plugins/`、`skins/` 要一起打进去。

备份文件放在 web root 之外（`/tmp` 或专门的备份目录）。放在 `public/` 下等于对外公开。

### 覆盖文件

升级包不包含这些运行时文件，覆盖时别动：

```
config/installed.php     站点配置和密钥
.env                     本地覆盖（如果有）
config/plugins.php       启用了哪些插件
config/skins.php         启用了哪些皮肤
config/plugins/*.json     插件挂载路径
data/                    上传文件、GeoIP 数据
public/assets/avatars/   用户头像
public/assets/recovery/  恢复工单附件
public/assets/develops/  站点图标、应用图标、第三方登录图标
```

用 rsync 这类带删除的同步方式覆盖时，上面这些目录都要排除；装了自定义皮肤的话
`skins/` 和 `public/assets/skins/` 也一起排除。

覆盖新文件之后：

```bash
php bin/nomen upgrade
```

它把核心迁移和插件迁移一起跑，最后更新库里的版本号。只跑核心迁移用 `migrate`，
插件自己的迁移用 `plugins migrate`。

跨大版本升级前先看发布说明里有没有需要手动处理的迁移。

### 升级到 1.4.0

这个版本不需要手动改库，但**必须跑一次 `php bin/nomen upgrade`**（只覆盖文件不跑它，登录和日志会报错）：

- 四张表会加对外编号列 `unid`：`users`、`activity_logs`、`account_recovery_requests`、`family_groups`，
  老数据由 `upgrade` 自动补号。日志表大的站点这一步会慢一点（每批 500 行），别中途打断。
- 后台地址从 `/admin/users/3` 这类自增号换成 `/admin/users/01M7…`，旧书签会回到后台首页。
- 登录行为变了：默认登录保持 30 天（每次访问续期，最长 90 天），登录页的「记住我」换成
  「这是公共设备」。升级前已经登录的会话会在浏览器关闭后失效，重新登录一次就会拿到新的 30 天会话。
- `config/app.php` 里 `remember_me_ttl` 换成了 `login_ttl`（30 天）和 `login_max_ttl`（90 天）；
  自己改过这份文件的，把这两行合并进去。
- 回滚：旧版程序配新库（多出的 `unid` 列可空）能继续跑；但**新装站**的 schema 里 `unid` 是
  `NOT NULL`，不要直接降级到 1.3.x，老代码不会写这个列。

## 常驻进程

站内通知的邮件是排队发的，需要有人消费队列：

```bash
php scripts/mail-drain.php
```

用 systemd 或 cron 拉起。脚本自己用 `scripts/mail-drain.lock` 加锁，同时只会有一个实例在跑。

## 从源码部署

```bash
composer install --no-dev
cd frontend && npm install && npm run build
```

前端构建产物落在 `public/assets/dist/`，发布包里已经带好。改了 `frontend/` 下的任何文件都要重新构建。
