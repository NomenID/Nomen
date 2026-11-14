# 安装

两种装法：网页向导（推荐）和命令行。装完之后有几件事必须做，跳过会留下隐患。

## 环境要求

| 项 | 要求 | 检查命令 |
|---|---|---|
| PHP | 8.1 / 8.2 / 8.3 | `php -v` |
| 扩展 | `openssl`、`pdo_mysql` 或 `pdo_sqlite` | `php -m` |
| 可选扩展 | `gd`、`fileinfo`、`curl`、`redis` | 同上 |
| 数据库 | MySQL 5.7+ / MariaDB 10.3+ / SQLite 3.35+ | `mysql -V` |
| Web 服务器 | nginx（Apache 也行，样例给的是 nginx） | — |

可选扩展缺了会**降级**，不报错：没有 `gd` 时头像上传变成填链接，没有 `fileinfo` 时按扩展名判断，
没有 `redis` 时会话和缓存退回文件。`php bin/nomen doctor` 会把当前能力列出来。

## 一、放文件

```bash
cd /www/wwwroot
unzip nomen-<版本>.zip -d nomenid
```

**web root 指向 `public/`，不是项目根。** 指错了 `config/`、`data/` 全都能被下载。

```nginx
root /www/wwwroot/nomenid/public;
index index.php;

location / {
    try_files $uri $uri/ /index.php$is_args$args;
}

location ^~ /plugin-assets/ {
    try_files $uri /index.php$is_args$args;
}

location ^~ /.well-known/ {
    try_files $uri /index.php$is_args$args;
}
```

`try_files` 少了只有首页能开；`/plugin-assets/` 少了插件样式全丢；`/.well-known/` 少了 OIDC 发现文档 404。

## 二、权限

```bash
chown -R www:www /www/wwwroot/nomenid
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod -R 775 data public/assets/avatars public/assets/recovery public/assets/develops
```

发布包里解出来的文件常常是 666，**必须改成 644**，否则面板和脚本改不了、也可能被当可执行文件处理。

## 三、跑安装向导

浏览器打开站点根，会 302 到 `/nomen-install/`。步骤：

1. **环境检测**：扩展、目录写权限、PHP 版本。有红的先修。
2. **数据库**：填主机、库名、账号、密码。**用这个库自己的专用账号，别用 root**。
3. **站点信息**：站点名、地址（`https://` 开头，末尾不带斜杠）、默认语言、是否允许切换语言。
   这一步还有「短地址」开关——服务器没配伪静态就关掉。
4. **管理员**：账号、邮箱、密码。
5. **扩展**：勾选要启用的插件。
6. **邮件**：SMTP。可以留空，之后在后台补。
7. **确认**：检查一遍，点安装。

装完把 `public/nomen-install/` 删掉或改名（可选，但公网站点建议删）。

## 四、装完必做

### 1. 设 pepper

```env
APP_PEPPER=<64 位随机字符串>
```

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

**pepper 一旦有用户就绝不能再改**：它参与全部密码哈希，改了所有人都登不进去，也回滚不了。
安装器会生成一个，但**老站从别的程序迁过来时要确认设置**。没设的表现是错误日志里
`[SECURITY] APP_PEPPER 未配置`。

### 2. 关掉调试

```env
APP_DEBUG=0
```

### 3. 配 SMTP

不配也能用，只是不发信（注册激活、找回密码、通知都是静默跳过）。

### 4. 检查一遍

```bash
php bin/nomen doctor
php bin/nomen plugins list
curl -s https://你的站点/api/version
```

`doctor` 会报数据库连接、目录权限、pepper、可选扩展。

GeoIP 和搜索这些插件要额外的依赖（geoip2 / mmdb、外部搜索引擎），不装也能跑，只是功能空转；
装法见[官方插件](plugins/index.md)。

## 五、命令行安装

不想用网页向导：

```bash
php bin/nomen install
```

交互式问同样的问题。适合脚本化部署，装完同样是上面那四件事。

## 六、升级

```bash
# 1. 备份
mysqldump --single-transaction -u<用户> -p <库> | gzip > /tmp/nomen-db-$(date +%Y%m%d).sql.gz
tar czf /tmp/nomen-files-$(date +%Y%m%d).tar.gz -C /www/wwwroot nomenid

# 2. 替换文件，排除这些
#    config/installed.php  .env  data/  public/assets/{avatars,recovery,develops}/  config/plugins/
#    config/plugins.php  config/skins.php  public/.user.ini（被 chattr +i 锁着）
#
#    plugins.php 和 skins.php 里的 enabled 列表是站点配置，不是发行包模板——
#    被包覆盖会把你启用的插件 / 皮肤重置回默认。

# 3. 迁移
php bin/nomen upgrade
php bin/nomen plugins migrate

# 4. 验证
curl -s https://你的站点/api/version?action=thissite
```

升级细节和「什么算破坏性改动」见 [兼容与升级](internals/compatibility.md)。

## 七、本地跑一个

不配 nginx 也能跑，PHP 内置服务器够了：

```bash
php -S 127.0.0.1:8002 -t public router.php
```

`router.php` 模拟 nginx 的 `try_files`：

```php
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$target = __DIR__ . '/public' . rtrim($path, '/');
if ($path !== '/' && is_file($target)) {
    return false;
}
// PHP 内置服务器对带扩展名的地址会把 SCRIPT_NAME 设成请求路径本身，
// 真实 nginx 下是 /index.php。不修正的话 /plugin-assets/*.css、/sitemap.xml 会 404。
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require __DIR__ . '/public/index.php';
```

## 故障排查

| 现象 | 原因 |
|---|---|
| 所有页面 404，只有首页能开 | 少了 `try_files` |
| 目录地址（`/w/`）404 | `try_files` 少了 `$uri/` |
| 插件样式全丢、页面能开 | vhost 里有 `location ~ .*\.(js|css)?$` 抢在 PHP 前面，加 `^~ /plugin-assets/` |
| 500 且日志里有 `[nomen] [xxxxxxxx]` | 拿这个编号 grep 错误日志 |
| 一直 302 到 `/nomen-install/` | 没生成 `config/installed.php`，安装没完成 |
| 谁也登不进去 | pepper 变了，或者库和 `installed.php` 不是一套 |
| 中文变成问号 | 库连接不是 utf8mb4 |

## 检查清单

- [ ] root 指向 `public/`
- [ ] `try_files` 带 `$uri/`，`/plugin-assets/` 和 `/.well-known/` 放行
- [ ] 文件属主 `www:www`、644，可写目录 775
- [ ] `APP_PEPPER` 是随机值且记在安全的地方
- [ ] `APP_DEBUG` 关着
- [ ] SMTP 配了（或者明确不打算发信）
- [ ] `/api/version` 能返回
- [ ] 备份脚本挂上了
