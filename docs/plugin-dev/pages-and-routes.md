# 页面与路由

插件对外提供地址有三种方式，用途不同：

| 方式 | 地址 | 适合 |
|---|---|---|
| 特殊页 | `/special/<slug>` | 状态页、说明页、给管理员看的检查页 |
| 挂载点 + 路由 | 你自己定，默认 `/example` | 插件的正经入口，站长可能会改路径 |
| 只注册路由 | 固定路径 | 内部接口、资源出口、回调地址 |

## 特殊页

清单里登记就行，不用写路由：

```json
"specialPages": {
    "example": {
        "title": "Example status",
        "access": "public",
        "handler": ["Nomen\\Plugin\\Example\\Status", "render"]
    }
}
```

```php
namespace Nomen\Plugin\Example;

use Nomen\Src\Response;

final class Status
{
    public static function render(): string
    {
        return Response::viewPartial('@example/status', [
            'rows' => 42,
        ]);
    }
}
```

- 处理函数返回 **HTML 字符串**，核心把它放进 `templates/special.php` 那个外壳里（标题、面包屑由外壳负责）。
- `access` 是 `public` 或 `admin`。`admin` 未登录会 302 到登录页并带回跳地址，登录了但不是管理员给 403。
- `title` 写成英文原句，渲染时过 `t()`。

特殊页的好处是啥都不用管：地址固定、导航不占位、外壳跟站点皮肤一致。**能用特殊页就别自己造路由。**

## 挂载点 + 路由

挂载点声明「这个插件占了哪个公开地址」，站长能在后台「接入点」页看到并改：

```json
"mounts": {
    "portal": {
        "path": "/example",
        "title": "Example portal",
        "description": "Bookmarks and notes",
        "access": "public",
        "config": "base_path"
    }
}
```

`config` 指的是 `config/plugins/<slug>.json` 里的键名。站长改路径时写进那个文件，插件重新读：

```php
public static function basePath(): string
{
    $path = \Nomen\Src\Plugins::mountUrl('example');

    return $path ?? '/example';
}
```

`mountUrl()` 在插件没装时返回 `null`；自己站内用可以直接退回默认值。

路由要在 `boot` 里注册，路径从 `basePath()` 取：

```php
public static function boot(): void
{
    $base = self::basePath();

    Plugins::route('GET', $base, [Controller::class, 'index']);
    Plugins::route('GET', $base . '/{slug}', [Controller::class, 'show']);
    Plugins::route('POST', $base . '/{slug}/delete', [Controller::class, 'destroy'], ['auth']);
    Plugins::route('GET', $base . '/assets/{path:.*}', [Controller::class, 'asset']);
}
```

### 路径参数

`{name}` 匹配一段（不含 `/`），`{path:.*}` 匹配多段。参数按**名字**传进处理函数：

```php
final class Controller
{
    public function show(string $slug): void      // 对应 {slug}
    {
        // ...
    }

    public function asset(string $path): void     // 对应 {path:.*}
    {
        // ...
    }
}
```

名字必须和 `{}` 里的完全一致。写成 `$name` 而路由里是 `{slug}` 会报参数错误。

写法两种：

```php
Plugins::route('GET', $base . '/{slug}', [Controller::class, 'show']);   // 类会被实例化
Plugins::route('GET', $base . '/health', [Health::class, 'render']);     // 静态方法也行
```

### 中间件

第三个参数是中间件数组，现有两个：

| 名字 | 效果 |
|---|---|
| `auth` | 未登录 302 到 `/login`，带回跳地址 |
| `admin` | 在 `auth` 之上再要求管理员，否则 403 |

```php
Plugins::route('POST', $base . '/{slug}/delete', [Controller::class, 'destroy'], ['auth', 'admin']);
```

POST 处理函数里要自己校验 CSRF（`Csrf::verify()`），路由层不做这件事。

## 两个全局闸

未验证邮箱和「恢复模式」这两个闸在路由之前执行：

- 未验证邮箱的账号会被 302 到 `/activate`。插件页面不在白名单里，**所以别指望给未验证用户提供页面**。
- 恢复模式激活时，普通页面被拦住，但插件的 POST 接口仍然可达。真需要拦的地方自己判断：

```php
if (\Nomen\Src\RecoveryHelper::isActiveFor($userId)) {
    Response::error(403);
}
```

## 路由的顺序

插件路由在核心路由**之前**注册，同名精确路径会被核心覆盖。所以：

- 想盖掉一个地址，插件做不到（这是故意的）；
- 起一个特别宽的通配路由（比如 `/{path:.*}`）会把核心地址全截走，别这么干。

## 静态资源

两种：

1. `assets/` 目录 + `/plugin-assets/<slug>/<path>`，核心已经注册好，只用登记样式：

```php
Plugins::style('example', 'example.css');
Plugins::script('example', 'example.js');
```

2. 自己注册一条路由发文件（需要权限判断、或者文件不在 `assets/` 下时）。核心的 `/plugin-assets/` 只发白名单
   类型（css、js、图片、字体、json），路径不能跳出 `assets/`。

细节见 [模板与资源](templates-and-assets.md)。

## 一个完整的例子

```php
// plugin.json
"mounts": {
    "board": {"path": "/board", "title": "Board", "config": "base_path"}
}
```

```php
// src/Plugin.php
public static function boot(): void
{
    $base = self::basePath();

    Plugins::route('GET', $base, [Board::class, 'index']);
    Plugins::route('GET', $base . '/{slug}', [Board::class, 'show']);
    Plugins::route('POST', $base . '/{slug}/reply', [Board::class, 'reply'], ['auth']);
}
```

```php
// src/Board.php
final class Board
{
    public function index(): void
    {
        Response::view('@board/list', ['threads' => Threads::recent(20)], 'default');
    }

    public function show(string $slug): void
    {
        $thread = Threads::find($slug) ?? Response::error(404);

        Response::view('@board/thread', ['thread' => $thread], 'default');
    }

    public function reply(string $slug): void
    {
        Csrf::verify();
        // ... 写库
        Response::redirect(self::basePath() . '/' . rawurlencode($slug));
    }
}
```

`Response::view('@<slug>/<模板>', $数据, 'default')` 用站点默认布局；想让插件自带布局就写
`'@<slug>/<布局名>'`，皮肤仍然能覆盖。
