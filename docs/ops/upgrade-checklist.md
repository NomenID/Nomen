# 升级与发布检查清单

代码改完了、包打好了，真正容易出事的是「部署」这一步。这份清单按顺序走，能挡住绝大多数事故。

## 一、发布前

- [ ] `git status` 干净，没有陌生的临时文件（`.tmp_*`、调试脚本）
- [ ] 版本号只改了一处：`config/app.php` 的 `version`
- [ ] `php code-scripts/docgen.php --check` 通过
- [ ] `php docs-internal/i18n/extract.php --check` 通过
- [ ] `find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;` 没有语法错误
- [ ] 前端改过的话构建过（`npm run build`），产物在 `public/assets/dist/`
- [ ] 打包：`php code-scripts/build-dist.php --priority=normal --min-supported=<最低支持版本>`（前端没改可加 `--skip-frontend`）
- [ ] `dist/release.json` 已放到官方站 `data/releases.json`（自动更新的发布源读它）
- [ ] 包里有 `vendor/` 和 `public/assets/dist/`（缺了包会小很多，一眼能看出来）
- [ ] 包里有 `.nomen-package.json`（自动更新用它核对文件清单）
- [ ] 包大小和上一个版本差不多（差太多说明漏了东西）

## 二、上传

### 首选：让程序自己装（推荐）

```bash
# 传包到服务器任意位置，然后：
php bin/nomen deploy /tmp/nomen-<版本>.zip --dry-run   # 先看：覆盖什么、跳过什么、删什么
php bin/nomen deploy /tmp/nomen-<版本>.zip             # 确认后执行
```

它和自动更新走同一套 `UpdatePackage`：同一份「哪些文件不能碰」的规则、同一个预演、同一个备份和回滚。
`--dry-run` 打印的「跳过」列表就是站点自己的东西（`config/`、`vendor/`、上传目录……），
一个字节都不会动。这条路径**不需要你记排除清单**。

加 `--sha256=<值>` 可以顺带校验包来源；不给就用本地实算值，只保证包完整。

### 备选：rsync（只在需要精细控制时用）

手工 rsync 唯一的坑是 `--delete` 会把「包里有、站点也有但版本不同」和「站点有、包里没有」一起处理掉。
排除清单**不要手抄**，用命令生成：

```bash
php bin/nomen deploy:exclude
```

输出里有一行可以直接用的 rsync 参数。规则来自 `src/PackageRules.php`，和自动更新共用一份，
不会漂移。核心就一条：`config/` 整个排除，只放行 `config/app.php`（版本号和新配置键靠它下发）。

不管走哪条路，上传前都要：

- [ ] 备份线上：库 + 文件，**备份放 `/tmp`，不要放 web root**
- [ ] 站点开了自动更新的话，先确认 `data/updates/` 没有正在跑的更新（`update.lock` / `maintenance.json`），
      或临时关掉总开关，避免手动部署和自动更新撞在一起
- [ ] 比对线上文件的 md5，确认没有别人在改（并行改动很常见）

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
curl -s https://example.com/api/version

# 页面
for u in / /login /w/download /special/version; do
  printf '%-20s %s\n' "$u" "$(curl -s -o /dev/null -w '%{http_code}' https://example.com$u)"
done

# 错误日志
tail -30 /www/wwwlogs/example.com.error.log
```

- [ ] `/api/version` 是新版本号
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
| 站点私有文件被删（`config/home-*.php`、`vendor/` 里手装的依赖） | 用 `php bin/nomen deploy --dry-run` 先看；别用 `rsync --delete` 配手抄清单 |
| 上传目录被 `--delete` 删掉 | 非要用 rsync 时：`php bin/nomen deploy:exclude` 生成参数，`--dry-run` 先看 would delete |
| `config/installed.php` / `config/plugins.php` 被覆盖 | 这两个在 `PackageRules::PROTECTED_FILES` 里，装包时永远不碰 |
| 部署后样式全丢 | 先资源后模板；检查 `/plugin-assets/` 放行 |
| 迁移跑到一半失败 | 先备份；迁移写成可重入 |
| 只在自己浏览器看是新的 | 检查 CDN 和 `?v=` |
| 忘了跑迁移 | 用 `bin/nomen deploy` 会自动跑；手工传的话固定加上，做完 curl 版本号 |
