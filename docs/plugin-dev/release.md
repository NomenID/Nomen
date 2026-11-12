# 发布插件

插件的版本号、README、许可和发版流程，跟核心是两件独立的事。核心升级不该被迫带着插件一起升，插件也不该
假设自己总跟核心同步发版。

## 版本号

`major.minor.patch`，写在 `plugin.json` 的 `version`：

| 位 | 什么时候动 |
|---|---|
| `patch` | 只修 bug、改文案、换许可 |
| `minor` | 加了能力（新钩子、新页面、新命令） |
| `major` | 不兼容的改动：删了配置键、改了表结构、要求更高版本的核心 |

`requires.Nomen` 跟着改：用了新核心才有的接口就把下限提上去。**下限写低一点没坏处**，写高了等于把还
能用的老站点挡在外面。

## 发版前

```bash
# 1. 清单能读、插件能加载
php bin/nomen plugins list

# 2. 多语言齐全
php docs-internal/i18n/extract.php --check

# 3. 语法
find plugins/example -name '*.php' -exec php -l {} \;

# 4. 两种数据库都试过迁移
php bin/nomen plugins migrate example
```

第 4 条容易漏：`.mysql.sql` 写得再对，SQLite 那份可能有个语法差异，本地不试就得等用户报。

## 包里要带什么

```
example/
├── plugin.json        必须
├── src/
├── templates/         有页面就带
├── layouts/           插件自带布局
├── assets/            CSS / JS / 图片
├── i18n/              25 份
├── database/migrations/
├── README.md          必须：写清这个插件干什么、装在哪、开了哪些地址
├── LICENSE            必须：协议全文
└── .git/              不要带进发布包
```

`LICENSE` 第一段写作者自己的声明，空一行后接协议全文；`plugin.json` 的 `license` 写 SPDX 标识，两边一致。
源码文件头也照同一套写（这个仓库的习惯是七行：版权、许可、指 LICENSE、Source、Project）。

## README 必须写清的

- 插件干什么，装在哪里（`config/plugins.php` 的 `enabled`）
- 开了哪些地址：特殊页、挂载点、后台页、路由
- 有没有建表，跑过什么迁移
- 注册了哪些命令
- 需要什么 PHP 扩展、对核心版本的要求
- 许可

**别让下一个维护的人去猜。** 这一条是插件契约里唯一没法自动检查、但最影响长期维护的东西。

## 发布

```bash
git tag v1.2.0
git push origin main --tags
```

GitHub Release 里写这一版改了什么（用户能感受到的变化，不是 commit 标题），把 zip 或源码包挂上。
插件一般不需要单独的下载站：`git clone` 就是安装方式。

## 随核心包分发

如果插件要跟核心发布包一起发（目前是五个：`pages`、`open-platform`、`geoip`、`totp-qr`、`search`），
要做三件事：

1. 核心仓 `code-scripts/build-dist.php` 的 `$bundledPlugins` 加上 slug；
2. `config/plugins.php` 的 `enabled` 默认列表决定装完是否默认启用——**要谨慎**，默认启用意味着每个新站都多一份
   攻击面和维护面；`search` 就是随包但默认关闭的例子；
3. `plugins/README.md` 的「自带插件」表格加一行。

打包机会先把这些插件 clone 到 `plugins/` 再打包，缺一个脚本直接报错退出。

## 不随包分发

插件仍然可以只在自己仓库里发布，用户 clone 到 `plugins/<slug>` 再启用。这样核心包不会因为插件变大而变大。
官方那个 `quick-central-login` 就是这种。

## 升级别把用户的数据搞丢

- 迁移只往前加，已发布的文件不改。
- 配置键改名要同时读旧键（`Plugins::config()` 会把文件合并到默认值上，旧键还在就能读），
  在下一个大版本再删。
- 卸载插件**不等于**删表。用户删目录后数据还在库里是好事；真要清理，提供一个 `example:uninstall` 命令让
  用户自己决定。
