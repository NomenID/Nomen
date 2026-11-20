# 权限模型

Nomen 里「谁能做什么」是一套**组 + 能力**体系，判定只问能力，不问角色。搞混了很容易以为「登录了就能看」或者「管理员页面插件也能随便碰」。

三个参考系统各取一点：能力判定学 WordPress（`current_user_can`），组叠加学 MediaWiki（`$wgGroupPermissions`），插件声明学 Flarum（扩展自己注册 ability）。

## 三层结构

| 层 | 存在哪 | 谁改 |
|---|---|---|
| 能力 ability | 代码里注册，如 `users.manage` | 核心与插件 |
| 组 group | `config/permissions.php` 声明；后台规则覆盖写 `group_rules` 表 | 站长 |
| 谁的组 | `user_groups` 表 | 后台用户详情页 / CLI |

一个用户可以同时在多个组，能力取并集。默认组见 `config/permissions.php`：

- `*`：隐式组，所有请求者（含未登录）。别往这里放敏感能力。
- `user`：隐式组，已登录且状态正常的账号自动在内。
- `administrator`：管理员，默认 `*`（全部能力，包括插件将来注册的）。

`users.role` 还在，但只作兼容快照：判定以组为准，`role = 'admin'` 在没跑迁移的旧库上视同在 `administrator` 组。`user:promote` / `user:demote`、后台「授予管理员」都会两边一起改。

## 判定

```php
Gate::can('users.manage');                  // 当前会话
Gate::can('pages.edit', $page);             // 带上下文，走插件注册的 checker
Gate::allows('logs.view');                  // 模板友好别名
Gate::userCan($userId, 'logs.view');        // 指定用户（OAuth 令牌、CLI、后台）
```

规则如下，按顺序：

1. 用户的所有组求并集；**任一组显式 deny 该能力就拒绝**，否则任一组 allow 就通过。
2. 没有任何组提到这个能力 → 默认拒绝。
3. 注册了 checker 的能力，组判定通过后还要过 checker（「这条记录是不是我的」）。
4. `gate.check` 过滤钩子最后再给插件一票。

判定结果按请求缓存、不进 session：**改组或改规则，当前会话立刻生效**，不用重新登录。

## 路由中间件

```php
Plugins::route('GET', '/example', [$c, 'index'], ['auth']);                 // 登录就行
Plugins::route('GET', '/example/edit', [$c, 'edit'], ['auth', 'can:pages.manage']);
Plugins::route('GET', '/example/old', [$c, 'old'], ['auth', 'admin']);       // 旧写法，等价 can:admin.access
```

没有中间件的路由是公开的，匿名可访问。中间件名写错会 500，不会默认放行。

后台自己的接口按能力拆开了：用户页要 `users.manage`、日志页要 `logs.view`、设置页要 `settings.manage`……外壳 `/admin` 只需要 `admin.access`。`administrator` 组是 `*`，所以管理员看到的东西和以前一样。

## 站点 API

`Api::action/list/meta/prop` 的第三个参数：

| access | 谁 |
|---|---|
| `public` | 匿名可读 |
| `admin` | `admin.access`：管理员会话，或**管理员**的 OAuth Bearer 令牌 |
| 能力名，如 `users.manage` | 会话或令牌对应用户拥有该能力 |

写错 access 名会 500，不会默认放行。

## OAuth / OIDC 的 scope

| scope | 给什么 |
|---|---|
| `openid` | `sub`（应用级编号） |
| `profile` | 昵称、头像 |
| `email` | 已验证的邮箱 |

**scope 是给应用看的，不是给用户看的权限**，和这套能力体系是两个正交维度。用户确认页上看到的就是这几项；「用户能不能改密码」这类不归 scope 管。

`sub` 按应用生成（`user_app_subjects`），所以同一个用户在不同应用里是不同的身份——应用之间无法用它串号。

## 插件：声明能力，别自己判管理员

插件注册自己的能力（`boot()` 里调）：

```php
Permissions::register('pages.manage', [
    'label' => 'Manage pages',            // 词典键，后台网格里显示
    'description' => 'Create and edit documentation pages',
]);

// 带归属判定的能力：组授权通过后，还要过这个闭包
Permissions::register('pages.edit', [...], static function (int $userId, array $page): bool {
    return (int)$page['author_id'] === $userId;
});
```

然后：

```php
Gate::can('pages.edit', $page)   // false：不是作者
```

后台「权限」页会自动出现这一列，站长勾给哪个组就哪个组能用——**插件不用改核心，也不用在后台加开关**。

插件自己的资源仍然要自己校验归属。`Gate` 给的是「能不能碰这类东西」，不是「这条是不是你的」——后者要么用 checker，要么在查询里 `WHERE user_id = :me`：

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

## 命令行

```bash
php bin/nomen group:list                       # 组、成员数、各组生效能力
php bin/nomen group:list alice                 # 某人在哪些组
php bin/nomen group:assign alice support       # 加进组
php bin/nomen group:remove alice support       # 移出组
php bin/nomen perms:list                       # 全部已注册能力
php bin/nomen group:assign alice administrator # 把自己锁在门外时的救命命令
```

后台网格允许把 `administrator` 组改成什么都不许——那是站长的自由。真锁死了用最后一条命令救。

## 家庭与未成年

开启 `guardians` 功能后多一层**关系**，它不是能力：

- 未成年账号属于一个家庭组，监护人能看孩子的部分信息、能替孩子同意。
- 孩子账号没有自己的验证邮箱时，家长确认（`consent_at`）也算通过验证闸。
- 这层关系存在 `family_members`，`FamilyHelper` 里有现成的判断函数。

插件要做「只有监护人能看」的东西，用 `FamilyHelper`，别自己写 SQL 判断关系。

## 不是什么

- **钩子不是权限**。`run` 钩子返回 `false` 能中断后续回调，但不能用来做「这个人能不能登录」的判定。
- **皮肤不是权限**。皮肤只决定长什么样，模板里拿到的数据是控制器给的；皮肤能藏起一个按钮，但藏不住接口。
- **隐藏菜单不是权限**。`menu => false` 只是不进侧栏，地址照样能访问；要拦就得加中间件或 `access`。
- **`users.role` 不是权限来源**。它是兼容快照，判定看组；两边不一致时以组和 `role` 的并集为准。

## 检查清单

- [ ] 每个改数据的路由都有 `auth`（或更严）
- [ ] 每个 POST 都 `Csrf::verify()`
- [ ] 每次按 id 取数据都校验了归属
- [ ] 管理接口用中间件（`can:xxx` / `admin`），不在函数里手写 `if (!isAdmin()) return 404;`
- [ ] 插件的新功能注册了能力，而不是让站长自己去配
- [ ] 私有资源越权时返回 404，不是 403
