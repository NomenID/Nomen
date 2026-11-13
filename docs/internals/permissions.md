# 权限模型

Nomen 里「谁能做什么」分几层，不是一套东西。搞混了很容易以为「登录了就能看」或者「管理员页面插件也能随便碰」。

## 账号角色

只有两种：`user` 和 `admin`，存在 `users.role`。

```php
\Nomen\Src\Router::isAdmin();    // 每次都查一次库，别缓存到会话里
```

这个判断是**实时查库**的：管理员被撤权，当前会话立刻失效，不像缓存在 session 里那样要等重新登录。

## 登录状态

```php
$userId = \Nomen\Src\Session::get('user_id');   // null 表示未登录
```

会话有效期两层：`login_ttl`（30 天滑动，每次访问续期）和 `login_max_ttl`（90 天硬上限）。
「这是公共设备」登录的是浏览器会话 cookie，关浏览器即失效。

## 路由中间件

```php
Plugins::route('GET', '/example', [$c, 'index'], ['auth']);        // 登录就行
Plugins::route('GET', '/example/edit', [$c, 'edit'], ['auth', 'admin']);  // 还要管理员
```

没有中间件的路由是**公开的**，匿名可访问。

## 特殊页和后台页

- `specialPages` 的 `access`：`public` / `admin`。
- `adminPages` 注册的页面默认只有管理员能拿到数据（`/api/admin/plugin/<slug>` 上有 `admin` 中间件）。

## 站点 API

`Api::action/list/meta/prop` 的第三个参数：

| access | 谁 |
|---|---|
| `public` | 匿名可读 |
| `admin` | 管理员会话，或**管理员**的 OAuth Bearer 令牌 |

写错 access 名会 500，不会默认放行。

## OAuth / OIDC 的 scope

| scope | 给什么 |
|---|---|
| `openid` | `sub`（应用级编号） |
| `profile` | 昵称、头像 |
| `email` | 已验证的邮箱 |

**scope 是给应用看的，不是给用户看的权限**。用户确认页上看到的就是这几项；「用户能不能改密码」这类不归 scope 管。

`sub` 按应用生成（`user_app_subjects`），所以同一个用户在不同应用里是不同的身份——应用之间无法用它串号。

## 插件必须自己管的事

核心只提供上面这几层。插件自己的资源，核心**不知道该怎么判**，得自己写：

| 场景 | 谁判 |
|---|---|
| 这条书签是不是当前用户的 | 插件（`WHERE user_id = :me`） |
| POST 请求的 CSRF | 插件（`Csrf::verify()`） |
| 这个用户能不能看这条私有记录 | 插件 |
| 管理员的 OAuth 令牌够不够 | 插件（`Api::requireAdmin()` 只判管理员） |

例子：

```php
public static function destroy(string $slug): void
{
    Csrf::verify();

    $userId = (int)Session::get('user_id');
    $row = Store::find($slug);

    // 不是自己的：给 404 而不是 403，别让人试探出「这条存在」
    if ($row === null || (int)$row['user_id'] !== $userId) {
        Response::error(404);
        return;
    }

    Store::delete($slug);
    Response::json(['success' => true]);
}
```

**越权最常见的写法**是先取 id 再删，不校验归属。上面这段的重点是 `$row['user_id'] !== $userId` 那一句。

## 家庭与未成年

开启 `guardians` 功能后多一层关系：

- 未成年账号属于一个家庭组，监护人能看孩子的部分信息、能替孩子同意。
- 孩子账号没有自己的验证邮箱时，家长确认（`consent_at`）也算通过验证闸。
- 这层关系存在 `family_members`，`FamilyHelper` 里有现成的判断函数。

插件要做「只有监护人能看」的东西，用 `FamilyHelper`，别自己写 SQL 判断关系。

## 不是什么

- **钩子不是权限**。`run` 钩子返回 `false` 能中断后续回调，但不能用来做「这个人能不能登录」的判定。
- **皮肤不是权限**。皮肤只决定长什么样，模板里拿到的数据是控制器给的；皮肤能藏起一个按钮，但藏不住接口。
- **隐藏菜单不是权限**。`menu => false` 只是不进侧栏，地址照样能访问；要拦就得加中间件或 `access`。

## 检查清单

- [ ] 每个改数据的路由都有 `auth`（或更严）
- [ ] 每个 POST 都 `Csrf::verify()`
- [ ] 每次按 id 取数据都校验了归属
- [ ] 管理接口用 `admin` 中间件而不是在函数里 `if (!isAdmin()) return 404;`
- [ ] 私有资源越权时返回 404，不是 403
