# 模板覆盖

皮肤改版式的办法是**放一个同名文件**。核心渲染 `login` 时先找皮肤目录，找到就用你的，找不到才用核心的。

## 查找顺序

`Response::view('login', $data)`：

1. 当前皮肤的 `skins/<skin>/templates/login.php`
2. 默认皮肤的 `skins/<default>/templates/login.php`
3. 核心的 `templates/login.php`

插件模板（`@<slug>/<name>`）多一层：

1. 当前皮肤 `skins/<skin>/templates/@example/list.php`
2. 默认皮肤 `skins/<default>/templates/@example/list.php`
3. 插件自己的 `plugins/example/templates/list.php`

所以**插件模板也能被皮肤统一外观**——这是插件和皮肤能分开维护的关键。

## 该覆盖哪些

| 想改 | 覆盖 | 说明 |
|---|---|---|
| 登录页排版 | `templates/login.php` | 表单字段名、`action` 都不能改，改了登录就废 |
| 注册页 | `templates/register.php` | 同上 |
| 首页 | `templates/home.php` | 只是版式的话，标题和按钮去后台「首页」改更省事 |
| 特殊页外壳 | `templates/special.php` | 版本页、许可页都用它 |
| 账号恢复的四个页 | `templates/recovery*.php` | 四个是一组，改一个就一起改 |

每个模板能用哪些变量，看 [模板参考](../templates/index.md)——那份是从所有 `Response::view()` 调用点生成的，
比手写清单可靠。

## 变量是调用方给的

模板里的变量来自 `Response::view('login', [...])` 那个数组，皮肤不能凭空多要一个。想让模板多一个变量，
只能改核心代码——所以**覆盖模板时要照着原来的变量用**，别自己加。

一个实际例子（`templates/login.php` 的变量）：

```php
/**
 * @var string $error
 * @var bool $success
 * @var string $redirect_to
 * @var bool $requireCaptcha
 * @var array $failedMethods
 * @var array $methods
 */
```

改版式时把这些变量都保留，只动 HTML 结构和 class。

## 页面外壳和正文档次

布局（`layouts/`）负责外壳，模板（`templates/`）负责正文。两件事分开：

- 只改页头页脚 → 动布局
- 只改某一页内部 → 动模板
- 改配色 → 动 CSS 变量（见 [静态资源](assets.md)）

把整页 HTML 都抄进模板、让布局只剩 `$content` 也能跑，但这样一来核心更新布局时（比如加了个新的 meta）
你就落后了。**能只改布局就别抄模板。**

## 首页的三种模式

首页不是固定一个模板，取决于后台「首页」怎么配：

| 模式 | 渲染 |
|---|---|
| 默认 | `templates/home.php` |
| 指定 Pages 页面 | `templates/home-page.php`（正文来自页面内容） |
| `config/home-*.php` | `templates/home-custom.php`（外壳，内容由那个文件返回） |

覆盖 `home.php` 只影响第一种模式。站长换了模式，你的覆盖就不生效了——这是预期行为，不是 bug。

## 皮肤自己的片段

皮肤可以有自己的 `partials/`，布局里直接 `require`：

```php
<?php require __DIR__ . '/../partials/header-ad.php'; ?>
```

**不打算被覆盖的片段这样写没问题**；如果这个片段也想让「当前皮肤」被别的皮肤替换（比如你做了个皮肤家族），
就放进 `templates/` 用 `Skins::template()` 取：

```php
<?php require \Nomen\Src\Skins::template('_site_sky'); ?>
```

核心的两个公共片段（`_lang_picker`、`_mobile_menu`）就是这种用法，皮肤可以覆盖，插件也能复用。

## 别改核心文件

`templates/` 和 `public/css/` 是核心的，皮肤改了下次升级就被覆盖。所有改动都放在皮肤目录里：

```
skins/example/
├── layouts/
├── templates/
├── partials/
├── i18n/
└── assets/          部署时拷到 public/assets/skins/example/
```

## 检查清单

- [ ] 覆盖的模板里变量一个没少
- [ ] 表单的 `method` / `action` / 字段名 / CSRF 字段都没动
- [ ] 首页三种模式各切一次看过
- [ ] 换回默认皮肤，站点仍然正常（说明覆盖没有污染核心）
