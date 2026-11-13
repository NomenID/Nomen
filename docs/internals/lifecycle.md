# 一次请求走过的路

从访客点下链接到浏览器拿到 HTML，中间有固定的十来步。排查「为什么这里不生效」「为什么被弹到别的页」时，
按这个顺序对一遍，比到处加 `var_dump` 快。

## 阶段

| # | 在哪 | 做什么 |
|---|---|---|
| 1 | nginx | 静态文件直接发；`try_files` 把其余请求交给 `public/index.php` |
| 2 | `public/index.php` | 判断是否已安装，没装就 302 到 `/nomen-install/` |
| 3 | 同上 | 加载 `src/I18n.php`，全局 `t()` 从这里定义 |
| 4 | 同上 | 输出安全响应头（nosniff / DENY / Referrer-Policy / CSP frame-ancestors） |
| 5 | `Session::start()` | 读 cookie、恢复会话、按 `login_ttl` / `login_max_ttl` 判断要不要续期 |
| 6 | `I18n::init()` | 定语言：`?lang` > 账号偏好 > cookie > 浏览器 > 站点默认 |
| 7 | `Plugins::boot()` | 读 `config/plugins.php`、校验 `requires`、注册 autoload／钩子／特殊页／后台页／挂载点，再调各插件的 `boot` |
| 8 | 核心 `Nav::add()` 等 | 注册核心导航、后台菜单 |
| 9 | 两个闸 | 邮箱未验证 → `/activate`；恢复模式 → `/recovery/secure` |
| 10 | `new Router()` | 插件路由先灌，核心路由后灌（同名核心覆盖），后台插件页路由在核心之前 |
| 11 | `Router::dispatch()` | 精确匹配 → 带参数匹配 → 都没中给 404 |
| 12 | 控制器 | 查库、校验 CSRF、准备数据 |
| 13 | `Response::view()` | `Skins::template()` 找模板 → 渲染成 `$content` → `Skins::layoutFile()` 找布局 → 输出 |
| 14 | `Url::filterHtml()` | 无伪静态模式下改写站内链接，再交给浏览器 |

## 两个闸（第 9 步）

这两段在路由**之前**执行，所以插件页面也在它们管辖范围内。

**邮箱验证闸**：已登录、但一个收信地址都没验证的账号，除了激活流程自己的页面，一律 302 到 `/activate`。
白名单是 `/activate*`、`/logout`、`/settings/emails/verify*`。

两个例外不拦：

- `/nomen-api*`：站点 API 自己按令牌判权限，被弹去激活页就无法用程序访问。
- `/.well-known/openid-configuration` 和 `/oidc/*`：OIDC 的发现文档和公钥必须匿名可达。

**恢复模式闸**：账号进入安全修复流程后，**页面型 GET** 全部拉回 `/recovery/secure`，只放行
`/recovery*`、`/2fa/*`、`/biometric/*`、`/settings/biometrics`、`/settings/emails/verify`、`/activate*`、`/logout`、`/captcha`。
POST / AJAX 不拦——修复动作本身就是 POST，拦了接口就废了。

自己在插件里判断这两个状态：

```php
if (\Nomen\Src\RecoveryHelper::isActiveFor($userId)) {
    // 这个账号正在安全修复，插件自己的 POST 接口要不要拒？
}
```

## 路由顺序

```php
$router = new Router();

Plugins::applyRoutes($router);   // 插件路由
Admin::applyRoutes($router);     // 插件后台页的数据接口
$router->get('/plugin-assets/{slug}/{path:.*}', ...);
// ... 核心路由
```

插件路由先注册，但核心后注册会用**同名精确路径**覆盖它。所以：

- 插件盖不掉 `/login`、`/oauth/*` 这类核心入口，这是故意的；
- 反过来，插件可以用一条很宽的通配路由截走大量地址，别这么干。

## 渲染

`Response::view('login', $data, 'default')`：

1. `Skins::template('login')`：当前皮肤 → 默认皮肤 → 核心 `templates/`。
2. `extract($data)` 后 `require` 模板，缓冲成 `$content`。
3. `Skins::layoutFile('default')`：当前皮肤 → **默认皮肤**（没有核心兜底，所以皮肤必须自带三个布局）。
4. 缓冲布局输出，整段过 `Url::filterHtml()`——只在无伪静态模式下改站内链接。

`Response::viewPartial()` 走第一步和第二步，不套布局，直接返回字符串。插件给自己拼片段时用这个。

## 钩子在哪一步

| 钩子 | 时机 |
|---|---|
| `user.registered` / `user.login.succeeded` / `user.logout` | 控制器里，会话变动前后 |
| `nav.items` | 布局调 `Nav::items()` 时 |
| `layout.head` / `layout.body.start` / `layout.body.end` | 布局渲染时（由皮肤调用） |
| `footer.links` / `footer.html` | `Footer::render()` |
| `homepage.sections` / `homepage.links` | 首页渲染 |
| `admin.*` | `/api/admin/*` 返回数据前 |
| `meta.tags` | head 里 meta 组装完 |

完整清单看 [钩子参考](../hooks/index.md)。

## 排查用的顺序

出问题先定位在哪一步：

| 现象 | 大概在哪一步 |
|---|---|
| 一直 302 到 `/activate` | 第 9 步邮箱闸 |
| 一直 302 到 `/recovery/secure` | 第 9 步恢复模式 |
| 404 但地址是对的 | 第 11 步没匹配上（插件路由没注册？伪静态没配？） |
| 500 且页面只有纯文本 | `ErrorPage` 兜底，看错误日志 |
| 样式没加载 | 第 1 步（nginx 把 `/plugin-assets/` 拦了）或第 13 步（资源没登记） |
| 站内链接全变成 `?page=` | 无伪静态模式开着，正常 |
