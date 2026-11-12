# 模板与资源

插件的 HTML 只有两条出路：`templates/` 里的 PHP 文件，或者处理函数里直接拼字符串。**超过二十行的 HTML 就别拼了**，
放进模板，皮肤还能覆盖它。

## 模板

```
plugins/example/
├── templates/
│   ├── status.php        面板正文
│   └── list.php          列表页
├── layouts/
│   └── wide.php          插件自带的布局（可选）
└── assets/
    └── example.css
```

渲染：

```php
Response::view('@example/list', ['rows' => $rows], 'default');   // 套站点默认布局
Response::view('@example/list', ['rows' => $rows], '@example/wide'); // 套插件自己的布局
$html = Response::viewPartial('@example/status', ['rows' => 3]);   // 只要片段，不要布局
```

`@` 后面是插件的 slug。模板里能用的变量就是第二个参数数组里的键（`extract()` 过），所以：

```php
<?php
/**
 * @var array<int,array<string,string>> $rows
 */
?>
<ul>
    <?php foreach ($rows as $row): ?>
        <li><?= htmlspecialchars($row['title']) ?></li>
    <?php endforeach; ?>
</ul>
```

文件开头那段 `@var` 注释是给人的，不是必须的，但 PHP 编辑器靠它补全，值得写。

**所有输出都要转义。** 模板是纯 PHP，没有自动转义这一层，`<?= $row['title'] ?>` 就是 XSS。

### 皮肤能覆盖

查找顺序（`Skins::template()`）：

1. 当前皮肤 `skins/<skin>/templates/@example/list.php`
2. 默认皮肤 `skins/<skin>/templates/@example/list.php`
3. 插件自己 `plugins/example/templates/list.php`

插件自带布局同理，皮肤放在 `skins/<skin>/layouts/@example/wide.php`。所以插件管内容、皮肤管外观，
站长换皮肤时插件页面跟着变。

### 公共片段别自己复制

站点里现成的片段用核心提供的，别在插件里抄一份：

```php
require \Nomen\Src\Skins::template('_lang_picker');          // 语言选择器
echo \Nomen\Src\Response::viewPartial('_mobile_menu', [...]); // 移动端抽屉
```

`Skins::template()` 返回的是路径，`require` 进去，变量从当前作用域拿。皮肤同样能覆盖这些片段。

## 静态资源

放 `plugins/example/assets/`，走核心已经注册好的 `/plugin-assets/<slug>/<路径>`：

```php
Plugins::style('example', 'example.css');     // 输出到 <head>
Plugins::script('example', 'example.js');     // 输出到 </body> 前
```

登记过的资源由**布局**统一输出，插件不用自己写 `<link>`。所以可以在 `boot` 里按页面登记，只在对的页面上加载：

```php
public static function boot(): void
{
    $path = rtrim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''), '/') ?: '/';

    if ($path === '/special/example' || str_starts_with($path, '/example')) {
        Plugins::style('example', 'example.css');
    }
}
```

取一个资源的地址（在模板里手写 `<img>` 时用）：

```php
<img src="<?= htmlspecialchars(Plugins::assetUrl('example', 'icon.svg')) ?>" alt="">
```

`assetUrl()` 会生成 `/plugin-assets/example/icon.svg?v=<文件修改时间>`——版本号是文件 mtime，改了文件地址自动变，
不用手工换 `?v=`。

### 能发什么

| 类型 | 扩展名 |
|---|---|
| 样式 | css |
| 脚本 | js |
| 图片 | svg png jpg jpeg gif webp ico |
| 字体 | woff woff2 ttf |
| 数据 | json |

其它类型一律 404。`.php`、`.sql`、`.env` 这些永远发不出去，路径也不能跳出 `assets/`。

资源响应带 `Cache-Control: public, max-age=604800`（7 天）。**改了文件但版本号没变时**，CDN 和浏览器可能还拿旧的；
`assetUrl()` 的 mtime 版本号能解决这个，手写地址的话自己加 `?v=<8 位随机>`。

### CSS 用站点的变量

插件样式要跟着站点的深浅色走，别写死颜色：

```css
.example-card {
    background: var(--color-bg-secondary, #f6f8fa);
    color: var(--color-text, #1f2328);
    border: 1px solid var(--color-border, #d0d7de);
}
```

`var()` 的第二个参数是兜底值，皮肤没定义这个变量时用它。完整的变量表见 [皮肤的静态资源](../skin-dev/assets.md)。
品牌色只做点缀，别整块铺。

## 给别的组件补东西

有一种情况插件不输出页面，而是给核心的某个组件补能力。约定是**全局对象 + 能力探测**，核心拿不到就降级：

```js
// assets/example-qr.js
window.NomenQr = {
    render: function (uri) {
        return { size: 200, path: '...' };   // 核心拿这两个字段画 <svg>
    }
};
```

核心侧：

```js
if (window.NomenQr && typeof window.NomenQr.render === 'function') {
    // 用插件给的能力
} else {
    // 降级：显示文字让用户手动输
}
```

现有的例子是 `totp-qr` 插件：它在浏览器里画两步验证的二维码，密钥不出站；没装这个插件时设置页只显示密钥文本。
写这类插件记得**核心不知道你的存在也能跑**，别让缺了插件就白屏。

## 检查清单

- [ ] 模板里的每个 `<?=` 都有 `htmlspecialchars()`
- [ ] `@<slug>` 和目录名一致
- [ ] 资源都登记了 `Plugins::style()` / `script()`，没在模板里手写 `<link>`
- [ ] 颜色走 CSS 变量，深浅色都试过
- [ ] 手机上打开看过（插件的列表页最容易横向溢出）
