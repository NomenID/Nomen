# 迁移

`php bin/nomen migrate` 按文件名顺序跑这里还没跑过的 `.sql`，跑过的记在 `schema_migrations` 表里。

- 文件名用 `YYYYMMDD_说明.sql`，一个文件放一组相关的改动
- 只往前加：已经发布出去的文件不要改，要改就加新文件
- DDL 在 MySQL 里是隐式提交的，中间失败只能人工核对，所以一个文件尽量短
- 四个引擎写法不一样时，把文件名后缀标成 `.mysql.sql` / `.sqlite.sql`；只有一种方言会跑它。
  不带后缀的 `.sql` 表示四种引擎都能跑（只用标准 SQL）

插件自己的迁移放 `plugins/<slug>/database/migrations/`，用 `php bin/nomen plugins migrate` 跑，
记录在 `plugin_migrations` 表。规则同上；插件不要改核心表。
