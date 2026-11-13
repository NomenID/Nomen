# 兼容与升级

## 版本号在哪

只有一处：`config/app.php` 的 `version`。`build-dist.php` 从它取包名，`/special/version` 显示它，
`/api/version` 报它。

`/api/version` 分两档：

| 请求 | 报什么 |
|---|---|
| `GET /api/version` | **最近一次正式发版**的号（`data/build.json` 的 `release`） |
| `GET /api/version?action=thissite` | 本站**实际在跑**的号 |

为什么分两档：别的实例靠这个接口判断「有没有新版」。快速构建版（`1.5.0+qb.20261112`）不该让全网以为发了新版，
所以对外报正式号，只有问 `thissite` 才说实话。

## 升级流程

```bash
# 1. 备份（库 + 文件），备份别放 web root
mysqldump --single-transaction -u<用户> -p <库> > /tmp/nomen-$(date +%Y%m%d).sql
tar czf /tmp/nomen-files-$(date +%Y%m%d).tar.gz -C /www/wwwroot <站点目录>

# 2. 替换文件（排除 config/installed.php、.env、data/、上传目录）

# 3. 跑迁移
php bin/nomen upgrade

# 4. 验版本
curl -s https://<域名>/api/version?action=thissite
```

`upgrade` 做四件事：跑核心迁移、补 UNID（分批 500 行）、更新版本记录、清临时缓存。
**它不会**改 `config/` 里的东西，也不会动插件。

插件单独跑：

```bash
php bin/nomen plugins migrate
```

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
- [ ] `/api/version?action=thissite` 是新号
- [ ] 版本页没有残留的 `data/build.json`
- [ ] 无伪静态站点抽查过站内链接
