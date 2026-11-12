# 后台页面

后台外壳是 Vue 单页应用，插件页面仍然是**服务端返回的 HTML**：前端去 `/api/admin/plugin/<slug>` 取
`{title, description, tabs, body}`，把 `body` 塞进面板里。所以插件不用懂 Vue，只要输出 HTML。

## 注册

清单里写：

```json
"adminPages": {
    "bookmarks": {
        "title": "Bookmarks",
        "description": "Saved links and notes",
        "handler": ["Nomen\\Plugin\\Bookmarks\\AdminPage", "render"],
        "menu": "Bookmarks",
        "group": "Site",
        "order": 35
    }
}
```

或者在 `boot` 里写代码（等价）：

```php
\Nomen\Src\Admin::page('bookmarks', [
    'title'       => 'Bookmarks',
    'description' => 'Saved links and notes',
    'handler'     => [AdminPage::class, 'render'],
    'menu'        => 'Bookmarks',
    'group'       => 'Site',
    'order'       => 35,
]);
```

| 字段 | 说明 |
|---|---|
| `title` | 页名，写成英文原句，渲染时过 `t()` |
| `description` | 页名下面那行说明，同样过 `t()` |
| `handler` | 返回 HTML 字符串 |
| `menu` | 字符串 = 菜单文字；`false` = 不进菜单，只能直接访问 `/admin/<slug>` |
| `group` | 侧栏分组名，不写就跟核心页面平铺 |
| `order` | 越小越靠前，核心页面用的是 10～30 |

slug 只能用 `a-z`、`0-9` 和连字符，写错了日志里会有一条，页面不注册。

## 分标签页

```php
\Nomen\Src\Admin::page('bookmarks', [
    'title' => 'Bookmarks',
    'tabs'  => [
        ['id' => 'all',    'label' => 'All',    'handler' => [AdminPage::class, 'all']],
        ['id' => 'drafts', 'label' => 'Drafts', 'handler' => [AdminPage::class, 'drafts']],
    ],
]);
```

有 `tabs` 的时候 `handler` 不再使用。前端切标签是换 `?tab=<id>` 重新拉一次 JSON，所以每个标签的
handler 都要能独立渲染。

## 表单

表单加 `data-admin-ajax`，提交由外层接管：POST 到表单的 `action`，成功后面板自动刷新。

```php
<form method="post" action="/admin/bookmarks/save" data-admin-ajax>
    <?= \Nomen\Src\Csrf::field() ?>
    <label class="a-field">
        <span><?= htmlspecialchars(t('URL')) ?></span>
        <input class="a-input" type="url" name="url" required>
    </label>
    <button class="a-btn a-btn--primary" type="submit"><?= htmlspecialchars(t('Save')) ?></button>
</form>
```

- 想先弹个确认框：`data-admin-confirm="Delete this bookmark?"`（文字会被转义，直接写文案）。
- 处理完想跳回某个后台页：表单里带 `<input type="hidden" name="_return" value="admin">`，控制器里自己判断。
- **CSRF 必须带**，`Csrf::field()` 会输出隐藏字段；处理函数里 `Csrf::verify()`。
- 指向 `/admin/...` 的链接会被 SPA 接管，外部链接加 `target="_blank"`。

## 样式

后台自带一套类，直接用，别另抄一份：

| 类 | 用途 |
|---|---|
| `a-card` | 白色卡片容器 |
| `a-table` | 表格 |
| `a-btn` / `a-btn--primary` / `a-btn--danger` | 按钮 |
| `a-input` / `a-select` / `a-textarea` | 表单控件 |
| `a-field` | 标签 + 控件的包裹 |
| `a-tabs` | 标签栏 |
| `a-empty` | 空状态 |

需要额外 CSS / JS：

```php
\Nomen\Src\Admin::style('/assets/skins/example/admin.css');
\Nomen\Src\Admin::script('/js/example-admin.js');
```

两个都只在后台布局输出。路径是相对站点根的地址，不是插件目录。

## 改核心的后台页面

后台的每个列表都开了 `filter`，插件能往数据里加东西（不能删核心字段的语义）：

| 钩子 | 拿到什么 | 加什么 |
|---|---|---|
| `admin.menu.items` | 侧栏菜单项 | 自定义菜单项（不进 `pages` 注册表的那种） |
| `admin.dashboard.cards` | 概览页统计卡 | 插件自己的计数卡 |
| `admin.dashboard.site` | 概览页站点信息块 | 运行状态、版本之类的补充 |
| `admin.users.rows` | 用户列表行 | 每个用户加一列数据 |
| `admin.user.detail` | 用户详情 payload | 详情页加一块 |
| `admin.apps.list` / `admin.apps.detail` | 应用列表 / 详情 | 同上 |
| `admin.logs.rows` | 活动日志行 | 同上 |
| `admin.settings.data` | 设置页数据 | 设置页加分区 |
| `admin.settings.saved` | 保存后的设置值 | 跟着落库或清缓存 |
| `admin.plugin.page.body` | 插件后台页的 HTML | 在别人的插件页上加东西（少用） |

往 `admin.users.rows` 加字段时注意：**别把敏感值放进去**，后台列表会导出 CSV。

## 一个完整的例子

```php
namespace Nomen\Plugin\Bookmarks;

use Nomen\Src\Admin;
use Nomen\Src\Csrf;
use Nomen\Src\Response;

final class AdminPage
{
    public static function render(): string
    {
        $rows = Store::all();

        $html = '<div class="a-card">';
        $html .= '<form method="post" action="/admin/bookmarks/save" data-admin-ajax class="a-field">';
        $html .= Csrf::field();
        $html .= '<input class="a-input" type="url" name="url" placeholder="https://" required>';
        $html .= '<button class="a-btn a-btn--primary" type="submit">' . htmlspecialchars(t('Save')) . '</button>';
        $html .= '</form>';
        $html .= '</div>';

        if ($rows === []) {
            return $html . '<p class="a-empty">' . htmlspecialchars(t('Nothing saved yet.')) . '</p>';
        }

        $html .= '<table class="a-table"><thead><tr>';
        $html .= '<th>' . htmlspecialchars(t('URL')) . '</th>';
        $html .= '<th>' . htmlspecialchars(t('Added')) . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr><td>' . htmlspecialchars($row['url']) . '</td>'
                . '<td>' . htmlspecialchars($row['created_at']) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    public static function save(): void
    {
        Csrf::verify();

        $url = trim((string)($_POST['url'] ?? ''));
        if ($url !== '') {
            Store::add($url);
        }

        Response::json(['success' => true]);
    }
}
```

`save` 的地址不是 `/admin/...` 页面，而是一条普通 POST 路由，在 `boot` 里注册：

```php
Plugins::route('POST', '/admin/bookmarks/save', [AdminPage::class, 'save'], ['auth', 'admin']);
```

后台表单的 POST 目标可以是任意地址，`data-admin-ajax` 只要求返回 JSON。
