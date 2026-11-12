# 静态资源

皮肤的 CSS / JS 有两种放法，选一种就别混：

| 放法 | 适合 | 代价 |
|---|---|---|
| 内联在布局的 `<style>` / `<script>` 里 | 几十行的微调、骨架起步 | 不能缓存，改一次整页变大 |
| 放 `public/assets/skins/<slug>/` | 正经皮肤 | 部署时要多拷一步 |

核心的 CSS 在 `public/css/`，所有皮肤共用。**皮肤不要改核心文件**，升级会被覆盖。

## 放成文件

皮肤仓库里放 `assets/`，部署时拷到站点的 `public/assets/skins/<slug>/`：

```
skins/example/assets/skin.css
        ↓ 部署
public/assets/skins/example/skin.css
```

布局里引用：

```php
<link rel="stylesheet" href="/assets/skins/example/skin.css?v=3f9a1c7e">
```

`?v=` 用 **8 位随机字符**，别用日期或版本号（会把部署时间、版本暴露出去）。改过 CSS 就换一个新的值，
没改就别动——换了等于让所有人重新下载。

脚本同理：

```php
<script src="/assets/skins/example/skin.js?v=8b2d4e6f"></script>
```

## CSS 变量

皮肤改配色就覆盖这些变量，别去改选择器里的颜色。核心 `public/css/style.css` 里定义了一套（深色是默认，
`[data-theme="light"]` 下面是浅色），皮肤覆盖 `:root` 和 `[data-theme="light"]` 两处就够：

```css
:root {
    --color-bg: #0d1117;              /* 页面底色 */
    --color-bg-secondary: #161b22;    /* 卡片、面板 */
    --color-bg-tertiary: #21262d;     /* 更浅一层 */
    --color-border: #30363d;
    --color-border-hover: #484f58;
    --color-text: #e6edf3;
    --color-text-muted: #8b949e;      /* 次要文字 */
    --color-text-subtle: #6e7681;     /* 更弱的说明 */
    --color-primary: #2f81f7;         /* 品牌色 / 主按钮 */
    --color-primary-hover: #1f6feb;
    --color-primary-light: rgba(47, 129, 247, 0.15);
    --color-danger: #f85149;
    --color-success: #3fb950;
    --color-warning: #d29922;
    --color-radius: 8px;
    --color-radius-lg: 12px;
    --font: -apple-system, BlinkMacSystemFont, "Segoe UI", "Noto Sans SC", Helvetica, Arial, sans-serif;
    --font-mono: 'SF Mono', 'Fira Code', 'Cascadia Code', monospace;
}
```

浅色主题写 `[data-theme="light"] { --color-bg: #ffffff; ... }`，**两套都要给**，否则用户切主题会看到一半颜色没跟上。

组件里的用法：

```css
.example-card {
    background: var(--color-bg-secondary);
    color: var(--color-text);
    border: 1px solid var(--color-border);
    border-radius: var(--color-radius);
}

.example-card__hint {
    color: var(--color-text-muted);
}
```

加兜底值 `var(--color-bg-secondary, #161b22)` 更稳：皮肤自己漏定义时不会变成透明。

## 深色 / 浅色

- 只写一套颜色 = 只支持一种主题。要么两套都写，要么全部走变量。
- 别写 `@media (prefers-color-scheme: dark)`——站点的主题是用户手动切的，存在 `localStorage`，不跟系统走。
- 首屏防闪：布局里在 CSS 之前用内联脚本设 `data-theme`（骨架里有这段）。

## RTL

阿拉伯语、希伯来语、波斯语要镜像。核心有 `public/css/rtl.css`，RTL 语言时加载：

```php
<?php if (\Nomen\Src\I18n::isRtl()): ?>
    <link rel="stylesheet" href="/css/rtl.css?v=31472968">
<?php endif; ?>
```

皮肤自己的 CSS 里别写 `margin-left` / `padding-right` 这种方向性属性，用 `margin-inline-start`、
`padding-inline-end`、`text-align: start`。写了方向性属性就得在 `rtl.css` 里再镜像一遍，容易漏。

`<html dir="...">` 由布局输出（`I18n::dir()`），别写死成 `ltr`。

## 字体

`--font` 里已经带了中文回退（`Noto Sans SC`）。皮肤想换字体：

```css
:root {
    --font: "Inter", -apple-system, "Noto Sans SC", sans-serif;
}
```

自带字体文件放 `public/assets/skins/<slug>/fonts/`，`@font-face` 里用绝对地址；`.woff2` 优先，`.woff` 兜底。
**别引外部字体 CDN**，有些地区直接加载不出来，页面会闪一下。

## 图片

- 皮肤用到的图标优先 SVG，放在 `public/assets/skins/<slug>/`。
- 别在 CSS 里写 `background-image: url(../images/...)` 这种相对路径，皮肤文件被拷贝后相对关系会变；写绝对地址
  `/assets/skins/example/images/foo.svg`。
- 装饰性图片加 `alt=""`，有信息的图写清 `alt`。

## 检查清单

- [ ] 所有 `?v=` 是 8 位随机字符，没有日期
- [ ] 浅色主题下看过一遍（不是只测深色）
- [ ] RTL 语言下看过一遍
- [ ] 手机上打开过，没有横向滚动条
- [ ] 没有改动 `public/css/` 里的任何文件
