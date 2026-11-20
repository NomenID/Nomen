# 兼容与升级

## 版本号在哪

只有一处：`config/app.php` 的 `version`。`build-dist.php` 从它取包名，`/special/version` 显示它，
`/api/version` 报它。

`/api/version` **只报本站实际在跑的版本**（quickbuild 的 `+qb` 后缀也原样返回），不再分「最新正式版 / 本站版本」两档。

判断有没有新版看发布源：官方站由 `official` 插件提供 `/official-api/releases/latest`（读 `data/releases.json`），
普通站点默认没有这个接口，只在 `config/autoupdate.php` 里把 `feed` 指向官方地址。发布源带下载地址、
SHA-256、优先级和最低支持版本。

## 升级流程

最稳的是让程序自己装——它认识站点私有文件，会自己备份、自己跑迁移：

```bash
# 1. 备份（库 + 文件），备份别放 web root
mysqldump --single-transaction -u<用户> -p <库> > /tmp/nomen-$(date +%Y%m%d).sql
tar czf /tmp/nomen-files-$(date +%Y%m%d).tar.gz -C /www/wwwroot <站点目录>

# 2. 装包：先预演，再执行
php bin/nomen deploy /tmp/nomen-<版本>.zip --dry-run
php bin/nomen deploy /tmp/nomen-<版本>.zip

# 3. 验版本
curl -s https://<域名>/api/version
```

`deploy` 和自动更新走同一个 `UpdatePackage`：同一份规则、同一个预演、同一个文件备份。
`--dry-run` 打印的「跳过」列表就是站点自己的东西，一个字节都不动。

`upgrade` 做四件事：跑核心迁移、补 UNID（分批 500 行）、更新版本记录、清临时缓存。
**它不会**改 `config/` 里的东西，也不会动插件。

插件单独跑：

```bash
php bin/nomen plugins migrate
```

### 手工替换文件时

排除清单别手抄，用命令生成：

```bash
php bin/nomen deploy:exclude
```

输出一行现成的 rsync 参数，规则和装包逻辑同源。

## 文件分三类

装包时每个文件按三类处理，规则只在 `src/PackageRules.php` 写一份：

| 类别 | 例子 | 装包时 |
|---|---|---|
| 站点私有 | `.env`、`config/installed.php`、`config/plugins.php`、`config/skins.php`、`data/`、`config/plugins/`、上传目录、`public/.user.ini` | 不覆盖、不删除 |
| 已存在就保留 | `vendor/`、`composer.json`、`composer.lock`、`config/` 下除 `app.php` 的其他文件、`public/assets/brand/` | 缺的补上，有的不动 |
| 程序文件 | `src/`、`controllers/`、`templates/`、`locales/`、`public/`、`plugins/`、`skins/`…… | 覆盖；新版不要的按清单删除 |

`config/app.php` 是唯一的例外：它跟着新版走，版本号和新配置键靠它下发。

站点上如果加了程序不知道的文件（自定义首页模板、手装的依赖），写进 `config/autoupdate.php` 的 `protect`，
装包时一样不碰。

**为什么这件事要写进代码而不是写进文档**：以前打包脚本、部署命令、运维文档各有一份排除清单，
加一个站点私有文件要改三处。漏一处就在下一次部署时把文件删掉——不是没记录，是记录了三份、然后漂移了。

## 什么算破坏性改动

| 改动 | 破坏性 | 为什么 |
|---|---|---|
| 加表 / 加列（可空） | 否 | 老数据不用动 |
| 加钩子 / 加参数（追加在末尾） | 否 | 老回调忽略多出来的参数 |
| 改钩子参数顺序 / 删钩子 | 是 | 插件回调会拿到错位的数据 |
| 改核心表结构（删列、改类型） | 是 | 插件可能读那一列 |
| 改 `plugin.json` 必需字段 | 是 | 老插件加载不了 |
| 改模板变量名 | 是 | 皮肤覆盖的模板会拿到 undefined |
| 加模板变量 | 否（但皮肤用不到） | 老模板忽略新变量 |
| 改 `config/` 键名 | 是 | 老站配置失效，静默回落默认值 |

破坏性改动要落在 `major` 位，并且在升级文档里写清怎么迁移。

## 老站升级时的静默差异

比崩溃更麻烦的是「不报错但行为变了」：

- `config/installed.php` 里缺的新键会用默认值。多数情况下没问题，但 `pretty_urls` 这种**没写等于 true**的键
  要留意。
- 插件的 `requires.Nomen` 不满足时会被**静默跳过**，只在 `error_log` 里记一行。升级后一定跑
  `php bin/nomen plugins list` 看一遍有没有 `未启用` 的。
- 皮肤缺布局时会回退到默认皮肤，看着「样式怪怪的」而不是报错。
- `data/build.json` 是快速构建版的标记，正式包不带它；从快速构建版切到正式包时要删掉它，
  否则版本页一直显示构建信息。

## 无伪静态模式

`config/installed.php` 的 `app.pretty_urls`：

| 值 | 行为 |
|---|---|
| `true` / 没写 | 正常路径（`/login`） |
| `false` | 查询串模式（`index.php?page=login`） |

不开伪静态的站点，`Router` 从 `$_GET['page']` 取路径，`Response::redirect()` 和模板里的站内链接会被
`Url::filterHtml()` 改写。**插件和皮肤不用为此改代码**，但手写的绝对地址要过 `Url::to()`：

```php
$url = \Nomen\Src\Url::to('/example/list', ['page' => 2]);
```

自写的 `<a href="/example">` 在查询串模式下会被 `filterHtml()` 改掉（只改 `public/` 下不存在真实文件的地址），
所以一般不用管；但用 JS 拼的地址改不了，得自己调 `window.Nomen.url()`。

## 运行环境

| 项 | 要求 |
|---|---|
| PHP | 8.1 / 8.2 / 8.3（CI 三档都跑） |
| 扩展 | `openssl`、`pdo_mysql` 或 `pdo_sqlite`；可选 `gd`、`fileinfo`、`curl`、`redis` |
| MySQL | 5.7+ / 8.0 / MariaDB 10.3+ |
| SQLite | 3.35+ |

没有 `openssl` 时 OIDC 只发普通 OAuth（不发 id_token）；没有 `gd` 时上传头像降级成填链接；
没有 `fileinfo` 时上传走扩展名判断；没有 `redis` 时会话和缓存回退到文件 / 数据库。

**这些降级都是静默的**，`php bin/nomen doctor` 会把当前环境的能力列出来。

## 迁移的兼容写法

写迁移时假设「这个站的版本可能很老」：

```sql
-- 好：可空列，老数据不用回填
ALTER TABLE `bookmarks` ADD COLUMN `note` text NULL;

-- 坏：非空列没有默认值，老数据直接违反约束
ALTER TABLE `bookmarks` ADD COLUMN `note` text NOT NULL;
```

不可逆的改动（删列、改类型）要单独一个版本，并且先让所有读它的代码停止读它，再删。

## 检查清单

- [ ] 升级前备份了库和文件，备份不在 web root
- [ ] `upgrade` 和 `plugins migrate` 都跑了
- [ ] `plugins list` 里没有被静默跳过的插件
- [ ] `/api/version` 是新号
- [ ] 版本页没有残留的 `data/build.json`
- [ ] 无伪静态站点抽查过站内链接
