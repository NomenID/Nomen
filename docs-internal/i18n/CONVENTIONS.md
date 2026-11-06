# 多语言包裹规范（t() 改造统一口径）

这一页是「全站文本包 t()」的工作规范。改任何一个文件前先读完。

## 基础机制（已就位，不要重复造）

- 全局函数 `t(string $key, array $params = []): string`，定义在 `src/I18n.php`，入口已加载。
  PHP 模板和控制器里直接 `t('中文原文')`，不需要 use、不需要 new。
- 键就是**现在的简体中文原文**。当前语言没有翻译时回退 en，再没有就原样返回键 —— 所以键必须
  和改造前显示的字符串**逐字节一致**（不改标点、不改措辞、不修 typo）。
- 插值占位符：`{:name}`。拼接句必须改成占位符：
  ```php
  // 改前
  '验证码将在 ' . $min . ' 分钟内有效'
  // 改后
  t('验证码将在 {:min} 分钟内有效', ['min' => $min])
  ```
  JS 同理：`'共 ' + n + ' 条'` → `t('共 {:n} 条', { n: n })`。
- JS 侧的全局 `t()` 在 `app.js` 顶部（`window.t`），别的 .js 文件直接用。
- Vue 侧：`import { useT } from '相对路径/composables/useT.js'`，`const { t } = useT()`。

## 词典按域分（插件 / 皮肤有自己的 i18n/）

- 核心词典在 `locales/`；插件词典在 `plugins/<slug>/i18n/`，皮肤词典在 `skins/<slug>/i18n/`，
  每个语言一份 `<lang>.json`（清单见 `locales/languages.php`），键同样是简体中文原文。
- 运行时按「核心优先、扩展补缺」合并（`src/I18n.php` 的 `loadLocale()`），前端词典
  `/i18n/<lang>` 同样合并。所以插件/皮肤里用核心已有的键（`t('保存')` 这类）不用自己备译文，
  只有核心没有的键才进自己的 `i18n/`。
- 插件清单的 `description`、`specialPages.title` 和皮肤清单的 `description` 渲染时也过 `t()`，
  想翻译就在各自 `i18n/` 里放同键译文；没翻译就原样显示中文。
- 生成与校验都按域走：`php docs-internal/i18n/extract.php` 生成各域的 zh-hans.json；
  `--check` 校验每个域每个语言键齐全、占位符一致。新增 t() 调用后先跑生成再补翻译，提交前 `--check` 必须全绿。
- 站点语言设置：`config/installed.php` 的 `app.language_default`（默认语言）和
  `app.language_allow_switch`（关掉后固定默认语言，选择器、`?lang=`、账号偏好全停），
  安装器第 3 步可以选；`.env` 的 `APP_LANGUAGE` 也能临时覆盖默认语言。

## 什么要包，什么不包

**要包**（用户看得见的）：
- 模板 HTML 正文、按钮文字、标题、`placeholder`、`title` / `aria-label` 属性、`<option>` 文字
- 控制器里 `Session::flash('error'/'success', ...)` 的消息
- JSON 响应里的 `'message'` / `'error'` 值
- JS 里的 toast / alert / confirm 文案、校验提示、按钮默认值
- 展示点上的**标签常量**：登录方式名、恢复方式名、OAuth scope 描述这类 —— 在「输出给页面」的那一行包
  `t($label)`，常量定义本身不动（键=中文原文）。

**不包**（保持中文 / 不动）：
- `admin/` 模板、`admin_layout.php`、`admin.js`、AdminController —— 管理后台保持中文
- **存进数据库的文本**：`ActivityLog::record()` 的 `summary` / `changes`、`NotificationHelper` 写入
  通知表的内容 —— 那是数据不是界面
- `error_log()` 调试信息、代码注释、路由路径、CSS 类名、非中文的字符串（OAuth 协议错误码等）
- 已经包过 `t(...)` 的字符串（跳过，别二次包裹）

## 逐字节的格式规则

1. `htmlspecialchars()` 照旧：原来是 `<?= htmlspecialchars($x) ?>` 的地方改成
   `<?= htmlspecialchars(t($x)) ?>`（t 输出的是词典内容，一样要转义）；原来裸输出的保持裸输出。
2. 属性里的中文：`aria-label="消息中心"` → `aria-label="<?= t('消息中心') ?>"`。
3. JS 字符串引号冲突：原文里有单引号的，外层换成双引号或转义，**保证 `node --check` 通过**。
4. 不改任何逻辑、缩进、换行结构 —— 只包字符串。diff 里应该只有「原文 → t('原文')」。
5. PHP 双引号字符串里的 `"` 和 `$` 注意转义层级，包完必须 `php -l` 通过。

## 改完自检（每个文件都要过）

- PHP：`php -l <file>` 无错
- JS：`node --check <file>` 无错
- Vue：肉眼确认 `<template>`/`<script>` 结构没破（构建验证由主线统一跑）

## 交付格式

报告里列出：改了哪些文件、各包了多少条、**故意跳过的字符串及原因**（拿不准是不是界面上的一律列出来）。
