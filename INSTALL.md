# 安装

Nomen 最后只需要一份配置文件：`config/installed.php`。里面有数据库密码和 pepper，**不要提交、不要外传**。

装法有两种，结果一样：网页向导（推荐）和命令行。

## 先确认 web root 是 `public/`

入口在 `public/index.php`，nginx / Apache 的 root 必须指到 `public/`。

指到项目根的话，你会看到根目录那个 `index.html`——它在提醒你改这里，不是一个正常页面。

root 改到 `public/` 之后这个文件就打不开了，可以删掉。删的是项目根目录那个 `index.html`，`public/` 里的文件别动。

## 伪静态（装之前先测）

安装页第 1 步会自动检测。也可以直接访问 `/__rewrite_probe`：

- 返回 `ok` → 伪静态没问题
- 返回 404 → 没配，除首页外的地址（`/login`、`/special/version`）都会 404

nginx：

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

Apache：`public/.htaccess` 已经带了规则，确认 `mod_rewrite` 开了、站点允许 `AllowOverride All`。

装完打开 `/login` 是 404 或者直接报错，先回来看这里，不用怀疑程序。

## 方式一：网页向导

1. 把代码放到站点目录（发布 zip 解压即可，`vendor/` 和前端产物都在里面）
2. 浏览器打开站点，没装过会自动跳到 `/nomen-install/`
3. 五步：环境检查 → 数据库 → 站点与管理员 → 扩展 → 安装
4. 装完按页面提示收尾：
   - PHP 能写 `config/` 时，`installed.php` 已经自己生成好了
   - 不能写时，页面会给一份内容，下载成 `installed.php` 传到 `config/`
   - **把 `public/nomen-install/` 删掉或改名**——留着等于把安装页开放给所有人
5. 打开站点，用刚建的管理员登录

## 方式二：命令行

```bash
php bin/nomen install
```

会依次问数据库、站点信息、要启用的扩展、管理员账号，然后建库、导表、建管理员、写 `config/installed.php` 和 `config/plugins.php`。

已经装过会拒绝，确实要重装加 `--force`。用 root 跑的时候配置文件会自动改成目录属主，免得 PHP-FPM 读不到。

## 数据库怎么准备

支持 MySQL 8、MariaDB 10.6+ 和 SQLite 3.35+，装的时候选一种。

### SQLite

不用建库、不用账号，填一个文件路径就行（默认 `database/nomen.sqlite`，相对路径按项目根算）。目录得让 PHP 能写，安装器会自己建文件。并发写入靠 WAL + `busy_timeout`，小站够用；站点被多个应用共用、写入频繁时用 MySQL / MariaDB 更稳。

### MySQL / MariaDB

安装器会尽量自己建库，但面板建的数据库账号通常没有建库权限，先建好更省事。宝塔面板：数据库 → 添加数据库，字符集选 `utf8mb4`。

或者自己来：

```sql
CREATE DATABASE `nomen` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nomen'@'localhost' IDENTIFIED BY '换成随机密码';
GRANT ALL PRIVILEGES ON `nomen`.* TO 'nomen'@'localhost';
FLUSH PRIVILEGES;
```

表结构不用手动导，安装器会跑对应方言的 schema（`database/schema.sql` 或 `database/schema.sqlite.sql`，各 22 张表）。

## 前端

发布 zip 里已经带好 `public/assets/dist/`，跳过这步。从源码部署才需要：

```bash
cd frontend
npm install
npm run build
```

## nginx

样例在 `config/nginx.conf`。关键两行：

```nginx
root /path/to/nomen/public;

location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

宝塔用户：站点设置 → 伪静态，把这行填进去。**少了 `try_files` 就只有首页能开，`/login` 这些全是 404。**

## 邮件

验证码、恢复链接、通知都走 SMTP。装完在 `config/installed.php` 的 `smtp` 段里填，或者用 `.env` 覆盖（见下面）。

通知邮件是排队发的（`notifications.email_sent_at`），要有个常驻进程消费：

```bash
php scripts/mail-drain.php
```

用 systemd 或 cron 拉起，同时只能有一个实例（脚本自己用 `scripts/mail-drain.lock` 加锁）。

## 可选：GeoIP

注册页按 IP 预填国家、活动日志显示地点，靠 `plugins/geoip`。

```bash
composer require geoip2/geoip2
```

再去 MaxMind 下载 GeoLite2-City 放到 `data/GeoLite2-City.mmdb`（要同意它的条款，文件不进仓库）。管理员打开 `/special/geoip` 能看状态。

## 插件与后台

安装向导第 4 步可以勾选要启用的扩展，默认是 `open-platform`（开发者门户）和 `pages`（站内文档）。`geoip` 按需勾。装完还能直接改 `config/plugins.php`。

- `php bin/nomen plugins list`：看插件状态和插件注册的命令。
- `php bin/nomen plugins migrate`：跑插件自己的 `database/migrations`。
- `php bin/nomen plugins pages:sync`：把 Pages 插件 `content/` 里新增的文档补进库，不覆盖已经改过的页面。

管理后台的「应用」页可以不依赖开放平台直接建 OAuth 应用；「接入点」页列出协议端点和插件挂载点，Pages 的 `/w`、开放平台的 `/open` 都能在那里看到，能改路径的还能直接改。

## 配置覆盖（`.env`）

`config/installed.php` 是主配置；想临时覆盖某一项，可以在项目根放一份 `.env`（格式 `KEY=value`），优先级高于 `installed.php`。常用的键：`APP_URL`、`BRAND_NAME`、`BRAND_LOGO`、`BRAND_COPYRIGHT`、`BRAND_PRIVACY_URL`、`BRAND_TERMS_URL`、`BRAND_DEVELOPER_GUIDELINES_URL`、`BRAND_SUPPORT_NAME`、`DB_*`、`SMTP_*`、`SUPPORT_EMAIL`、`ADMIN_NOTIFY_EMAIL`、`DEFAULT_SKIN`、`APP_LANGUAGE`、`FEATURE_*`。切数据库用 `DB_DRIVER=mysql|mariadb|sqlite`，SQLite 另给 `DB_PATH`。

`APP_DEBUG=1` 开调试模式：报错页会显示异常类型、信息和调用栈。**只在本机排查问题时开**，公开站点开着会把路径等内部信息露给访客。平时报错页只显示一句通用提示和一个错误编号，管理员拿编号去 PHP 错误日志里搜就能找到完整原因。

## 语言

- 网页向导第 3 步可以选**默认语言**（浏览器语言匹配不上时用的那个）和**是否允许用户自己切换**；命令行安装器同样会问。
- 两项都写进 `config/installed.php` 的 `app.language_default` / `app.language_allow_switch`，手改这两个键也一样。`language_allow_switch` 关掉后全站固定默认语言：语言选择器、`?lang=`、账号里的语言偏好全部停用。
- 装完的站点想换默认语言，改 `installed.php` 里这两个键即可，不用重装。

## 可选功能

下面两项**默认关闭**，安装向导第 3 步可以勾选；装完也能改 `config/installed.php` 的 `app.features`：

```php
'app' => [
    // ...
    'features' => [
        'guardians' => true,   // 家庭与未成年监护：家长同意、家长邮箱、家庭页
        'country'   => true,   // 注册时填写国家 / 地区（含按 IP 预填）
    ],
],
```

也可以用环境变量覆盖：`FEATURE_GUARDIANS=1`、`FEATURE_COUNTRY=1`（`0` 为关闭）。

- 关掉 `guardians`：注册不按年龄要求家长邮箱，激活就是普通邮箱验证，`/family` 和家庭相关入口不出现；登录、恢复、后台统计里的家庭分支也一并停用。
- 关掉 `country`：注册流程不再有国家 / 地区这一步，资料里也不显示这一项。`guardians` 还开着时按平台默认同意年龄（16 岁）判断。
- 已装好的站点升级后两项都是关的。要让老用户继续用家庭功能，记得在 `installed.php` 里把 `guardians` 打开。

GeoIP（按 IP 查地点）本来就是一个插件，不需要就在 `config/plugins.php` 里把 `geoip` 去掉。

## 升级

先备份数据库，以及这几个不会随升级包走的东西：

```
config/installed.php   站点配置和密钥，覆盖掉就得以这个为准
.env                   本地覆盖（没改过就不用管）
data/                  上传的文件、GeoIP 数据
public/assets/avatars/ 头像
public/assets/recovery/ 恢复工单里的截图
```

**下载 zip 的**：把新包里除上面几项以外的文件覆盖上去就行。**用 git 的**：`git pull`。然后跑：

```bash
composer install --no-dev   # 依赖有变时
php bin/nomen upgrade
```

`upgrade` 就是核心迁移 + 插件迁移两条命令一起跑，跑完把库里的版本号更新成当前程序版本。

- `migrate` 只跑 `database/migrations/` 下没跑过的 SQL，记录在 `schema_migrations` 表；
  插件自己的迁移在 `plugins/<slug>/database/migrations/`，记录在 `plugin_migrations`。
- 方言专属的迁移按后缀区分：`.mysql.sql`、`.sqlite.sql`；不带后缀的 `.sql` 是通用写法。
- 忘了跑升级时后台概览页会挂一条「数据库版本和程序不一致」，跑完自己消失。

已经发布的迁移文件不会再执行，重复跑 `upgrade` 是安全的。

## 排错

| 现象 | 原因 |
|---|---|
| 首页只有一句「服务器内部错误，请稍后重试」 | 数据库连不上：看 `config/installed.php` 的 `db`，或者 `/www/wwwlogs/<域名>.error.log` |
| 首页能开，其它路径全 404 | 伪静态没配：`/__rewrite_probe` 返回 404 就是它，加 `try_files` 或 .htaccess 规则 |
| 页面能开但没样式 / 空白 | `public/assets/dist/` 没构建（发布 zip 里带了，源码部署要 `cd frontend && npm run build`） |
| 一直跳安装页 | `config/installed.php` 不在，或 PHP 读不到它（权限） |
| 收不到验证码 | SMTP 没填，或 `mail-drain.php` 没跑 |
| SQLite 报 `database is locked` | 写入撞车：确认库文件目录可写、`database/nomen.sqlite-wal` 没被删；并发高就换 MySQL / MariaDB |
