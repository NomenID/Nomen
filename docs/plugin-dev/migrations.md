# 迁移与命令

插件要存数据就建**自己的表**。核心表结构不是插件 API，跟着版本改，插件不能碰。

## 迁移文件

```
plugins/example/database/migrations/
├── 20261113_create_bookmarks.mysql.sql
└── 20261113_create_bookmarks.sqlite.sql
```

命名规则：`<日期>_<做了什么>.sql`，需要区分数据库时用 `.mysql.sql` / `.sqlite.sql` 后缀。

| 后缀 | 什么时候跑 |
|---|---|
| `.mysql.sql` | MySQL / MariaDB（`mariadb` 当成 `mysql` 处理） |
| `.sqlite.sql` | SQLite |
| `.sql` | 两种都跑（SQL 得写成两边都认的形式） |

按**文件名字典序**执行，所以日期前缀必须是 `YYYYMMDD` 这种定长格式，别写 `2026-1-3`。

跑：

```bash
php bin/nomen plugins migrate            # 所有已启用的插件
php bin/nomen plugins migrate example    # 只跑这一个
```

跑过的文件记在 `plugin_migrations` 表（`plugin` + `filename` 主键）。已经发布的迁移文件**不要改**——它不会重跑，
改了对已经跑过的站点没有任何效果，只会让新站点和旧站点的表结构不一样。要改就加一条新的。

## 写迁移的注意

```sql
-- MySQL
CREATE TABLE `bookmarks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `unid` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` int unsigned NOT NULL,
  `url` varchar(2048) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_unid` (`unid`),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
-- SQLite
CREATE TABLE bookmarks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  unid TEXT NOT NULL UNIQUE,
  user_id INTEGER NOT NULL,
  url TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX idx_bookmarks_user ON bookmarks (user_id);
```

几条经验：

- 表名不加前缀（核心也是裸表名）。
- 要对外露编号就加 `unid`，用 `\Nomen\Src\Unid::generate()` 生成，别把自增 id 露给用户。
- `user_id` 指核心的 `users.id` 是可以的（引用，不是修改），但**别加外键约束**：核心删用户时不希望被插件拦住，
  插件自己在用户删除后清理，或者留着孤儿行。
- 时间统一 `datetime`，别用 `timestamp`（MySQL 的时区和 2038 问题）。
- 索引按查询建，`user_id` 这类基本都要。

## 命令

插件可以注册 CLI 命令，用来补数据、重建索引、跑一次性修复：

```php
public static function boot(): void
{
    Plugins::command('example:prune', [Commands::class, 'prune'], '删掉三天前的书签');
}
```

```php
final class Commands
{
    public static function prune(): int
    {
        $db = \Nomen\Src\Database::getInstance();
        $stmt = $db->prepare('DELETE FROM bookmarks WHERE created_at < :cut');
        $stmt->execute([':cut' => gmdate('Y-m-d H:i:s', time() - 3 * 86400)]);

        return $stmt->rowCount();       // 返回整数会打印「完成（N）」
    }
}
```

返回值约定：

| 返回 | 输出 |
|---|---|
| 字符串 | 原样打印 |
| 整数 | `完成（N）` |
| `null` | 什么都不打印 |

```bash
php bin/nomen plugins list              # 插件 + 命令一起列出来
php bin/nomen plugins example:prune
```

命令名习惯 `<slug>:<动作>`。参数处理自己来：`$argv` 里能拿到整条命令，`php bin/nomen plugins example:sync --dry-run`
这种写法需要自己在处理函数里解析 `$GLOBALS['argv']`。

## 和核心迁移的区别

| | 核心 | 插件 |
|---|---|---|
| 目录 | `database/migrations/` | `plugins/<slug>/database/migrations/` |
| 命令 | `php bin/nomen upgrade` | `php bin/nomen plugins migrate` |
| 记录表 | 版本记录在 `app_meta` | `plugin_migrations` |
| 谁负责 | 核心维护者 | 插件作者 |

`upgrade` 还会做版本号记录、数据回填（比如 UNID 补号）这些事，插件不用管。

## 备份

迁移前先备份库，这是常识但值得写在插件的 README 里：

```bash
mysqldump --single-transaction -u<用户> -p <库名> > /tmp/nomen-$(date +%Y%m%d).sql
```

**备份别放在 web root 里**，`.sql` 文件会被直接下载。

## 检查清单

- [ ] `.mysql.sql` 和 `.sqlite.sql` 两份都写了，本地两种都跑过
- [ ] 文件名是 `YYYYMMDD_` 开头
- [ ] 没 ALTER 核心表
- [ ] 没加指向核心表的外键
- [ ] 命令跑完给出能看懂的输出（数字或一句话）
