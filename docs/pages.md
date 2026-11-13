# 站内页面（Pages）

在站点里写 Markdown 页面，不用另起文档站。默认挂 `/w`。

## 打开

随包自带、默认启用。挂载点：

```json
// config/plugins/pages.json
{"base_path": "/w"}
```

后台「接入点」能改。

## 写页面

后台 →「内容」→ 新建，或者导入目录：

```bash
php bin/nomen plugins pages:sync                                        # 插件自带 content/
php bin/nomen plugins pages:import docs --prefix=local-docs --update    # 导入目录
```

- `--prefix` 决定站内路径（`local-docs/install`）。
- `--update` 覆盖已有页面；不加只补缺。
- 目录**递归**，别把内部笔记放在被导入的目录下。
- 相对 `.md` 链接会被改写成站内地址；跨目录引用要写绝对地址。

## 侧边栏

`page_menus` 有两种模式：

- `manual`：后台手写条目（老站默认）。
- `auto`：按 slug 树生成，子目录成一组，当前页所在组展开。新页面自动出现。

## 数据

两张表，插件自己建（`CREATE TABLE IF NOT EXISTS`，不用跑迁移）：`pages`、`page_menus`。
老站升级后第一次访问会自动补 `page_menus` 的新列。

## 详细说明

地址清单、导入细节、模板与皮肤的边界、Markdown 能力的限制：见
[官方插件 / Pages](plugins/pages.md)。
