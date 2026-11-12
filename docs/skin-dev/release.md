# 发布皮肤

皮肤是独立项目：自己的仓库、自己的版本号、自己的许可，跟核心发布节奏无关。

## 版本号

`major.minor.patch`，写在 `skin.json` 的 `version`：

| 位 | 什么时候动 |
|---|---|
| `patch` | 改配色、修排版、换文案 |
| `minor` | 加东西：新的模板覆盖、新的配置项、新的片段 |
| `major` | 布局结构变了，或者不再支持旧版核心的某个布局 |

## 清单

```json
{
    "name": "Example",
    "version": "1.0.0",
    "description": "A calm paper-like skin with a narrow column",
    "license": "Apache-2.0",
    "author": ["Your Name"]
}
```

- `description` 是**英文原句**，渲染时过 `t()`，译文放 `i18n/<语言>.json`。
- `license` 写 SPDX 标识，和目录里 `LICENSE` 的全文一致。
- `slug` 就是目录名，不用写进清单。

## 启用规则

`config/skins.php`：

```php
return [
    'default' => 'example',
    'enabled' => ['nomen-2026', 'nomen-ssr', 'example'],
];
```

- `default` 是站点默认皮肤，`DEFAULT_SKIN` 环境变量优先。
- `enabled` 是允许用的列表，用户设置页只列这些。整个键不写表示不限制。
- **`default` 指向一个不存在的皮肤时会自动落到列表里第一个可用的**，站点不会白屏。
- 用户自己的选择优先：账号设置 → cookie → 站点默认。所以站长换默认皮肤，老用户还是看自己选的那个。

## 装上

```bash
git clone https://github.com/your/example-skin skins/example
```

再把 `example` 写进 `enabled`。要带独立 CSS / JS 的话，把 `assets/` 拷到 `public/assets/skins/example/`。

## 发版前

```bash
# 1. 清单能读
php bin/nomen doctor

# 2. 25 份词条齐全
php docs-internal/i18n/extract.php --check

# 3. 语法
find skins/example -name '*.php' -exec php -l {} \;
```

然后按这份清单手点一遍：

- [ ] 首页（三种模式各一次）
- [ ] `/login` `/register`（表单能提交）
- [ ] `/special/version` `/special/lang`
- [ ] 登录后的 `/dashboard` `/settings`（`app` 布局）
- [ ] `/admin`（`admin-app` 布局）
- [ ] 浅色 / 深色各看一遍
- [ ] 阿拉伯语看一次 RTL
- [ ] 手机宽度看一次

## 提交与发布

```bash
git tag v1.0.0
git push origin main --tags
```

Release 说明写清楚**看到的变化**（「登录页改成左右两栏，窄屏下堆叠」），不要写 commit 标题。
皮肤不需要单独的下载站，`git clone` 就是安装方式。

## 随核心包分发

官方皮肤随发布包发（`nomen-2026`、`nomen-ssr`）。第三方皮肤要进包得改核心的
`code-scripts/build-dist.php` 的 `$bundledSkins`，并且接受「每个新站点都会带上它」。一般不建议——皮肤是外观选择，
让站长自己 clone 更合适。

## 核心升级后要复查

核心加了新布局变量、新钩子、新模板时，皮肤可能要跟着改。升级后跑一遍上面的手点清单，重点看：

- 页面头部的 meta 还在不在（`layout.head` 钩子有没有被改动）
- 插件页面还能不能正常显示（插件模板的查找链变了没）
- 后台还能不能进（`admin-app` 布局的资产路径）

**皮肤坏了的表现通常很吓人**（整站 500 或者样式全丢），所以核心升级时官方皮肤要一起测。
