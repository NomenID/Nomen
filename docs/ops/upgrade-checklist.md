# 升级与发布检查清单

代码改完了、包打好了，真正容易出事的是「部署」这一步。这份清单按顺序走，能挡住绝大多数事故。

## 一、发布前

- [ ] `git status` 干净，没有陌生的临时文件（`.tmp_*`、调试脚本）
- [ ] 版本号只改了一处：`config/app.php` 的 `version`
- [ ] `php code-scripts/docgen.php --check` 通过
- [ ] `php docs-internal/i18n/extract.php --check` 通过
- [ ] `find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;` 没有语法错误
- [ ] 前端改过的话构建过（`npm run build`），产物在 `public/assets/dist/`
- [ ] 打包：`php code-scripts/build-dist.php`（前端没改可加 `--skip-frontend`）
- [ ] 包里有 `vendor/` 和 `public/assets/dist/`（缺了包会小很多，一眼能看出来）
- [ ] 包大小和上一个版本差不多（差太多说明漏了东西）

## 二、上传前

- [ ] 备份线上：库 + 文件，**备份放 `/tmp`，不要放 web root**
- [ ] 比对线上文件的 md5，确认没有别人在改（并行改动很常见）
- [ ] `rsync --dry-run` 看 `would delete` 列表，**确定要删的都是旧产物**

必须排除的路径（不同站点略有差异）：

```
config/installed.php
.env
data/
public/assets/avatars/
public/assets/recovery/
public/assets/develops/
config/plugins/
public/.user.ini          # 被 chattr +i 锁着，不排除 rsync 会报 23
vendor/                   # 线上有自己的依赖（geoip2 这类）
```

## 三、上传顺序

1. **先资源，后模板**。CSS/JS 全传完，最后才传引用新 `?v=` 的模板。反过来会出现「模板引用了新版本、
   资源还是旧的」，访客会拿到旧字节。
2. 解包出来的文件是 666，传完统一改：

```bash
find . -type f ! -perm 644 -exec chmod 644 {} \;
chown -R www:www .
```

3. 传完逐个校验 md5，别只看 rsync 的退出码。

## 四、部署后

```bash
# 迁移
php bin/nomen upgrade
php bin/nomen plugins migrate

# 版本
curl -s https://example.com/api/version?action=thissite

# 页面
for u in / /login /w/download /special/version; do
  printf '%-20s %s\n' "$u" "$(curl -s -o /dev/null -w '%{http_code}' https://example.com$u)"
done

# 错误日志
tail -30 /www/wwwlogs/example.com.error.log
```

- [ ] `/api/version?action=thissite` 是新版本号
- [ ] 首页 / 登录 / 文档 / 版本页 都是 200
- [ ] 插件资源 200（`/plugin-assets/<slug>/...`）
- [ ] 错误日志没有新的 `[nomen]` / `[Plugins]`
- [ ] 登录一次（真的登进去），不只是看页面能开
- [ ] 后台能进，概览页数据正常

## 五、CDN

- [ ] 静态资源的 `?v=` 换了，`cf-cache-status` 不是老的 `HIT`
- [ ] HTML 页面是 `DYNAMIC`（不缓存）
- [ ] 同名文件（比如 `nomen-x.y.z.zip`）覆盖后，公网 sha256 和源站对得上；对不上就让用户去 CF 面板清那个 URL

## 六、回滚

动手前就要想好：

```bash
# 文件回滚
tar xzf /tmp/nomen-files-<日期>.tar.gz -C /www/wwwroot

# 库回滚
mysql <库> < /tmp/nomen-db-<日期>.sql.gz     # 或者 gunzip -c | mysql
```

- **迁移不一定能回滚**（加列能忍，删列不能）。所以破坏性迁移要单独一个版本，先发一轮「只加不删」。
- 回滚后记得 `php bin/nomen upgrade` 把版本记录对齐，不然 `/api/version` 会报错号。

## 七、发布说明

给用户看的更新日志按「新增 / 改进 / 修复」分组，每条一句大白话说清**能感受到的变化**，
不写 commit 标题、不写内部模块名。

末尾附产物事实：文件名、大小、SHA-256、下载地址。

## 常见事故与预防

| 事故 | 预防 |
|---|---|
| 上传目录被 `--delete` 删掉 | dry-run 看 would delete，排除项写进脚本 |
| `config/installed.php` 被覆盖 | 部署脚本里硬编码排除 |
| 部署后样式全丢 | 先资源后模板；检查 `/plugin-assets/` 放行 |
| 迁移跑到一半失败 | 先备份；迁移写成可重入 |
| 只在自己浏览器看是新的 | 检查 CDN 和 `?v=` |
| 忘了跑迁移 | 部署步骤里固定加上，做完 curl 版本号 |
