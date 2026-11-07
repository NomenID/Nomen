# 多语言包裹规范（t() 改造统一口径）

这一页是「全站文本包 t()」的工作规范。改任何一个文件前先读完。

## 基础机制（已就位，不要重复造）

- 全局函数 `t(string $key, array $params = []): string`，定义在 `src/I18n.php`，入口已加载。
  PHP 模板和控制器里直接 `t('English key')`，不需要 use、不需要 new。
- 键是**英文原文**（MediaWiki 式；2026-11-08 起词典已全量迁英文键，zh-hans.json 是英文键→中文译文）。
  当前语言没有翻译时回退 en，再没有就原样返回键。**新加键一律默认英文，并当场把各语言译文写齐**
  （zh-hans 必须自己写中文；en 键=值不用译；`--check` 要求每个语言文件条目齐全，缺一条就红）。
  存量键的英文措辞别顺手改 —— 改一个键 = 每个语言文件都要跟着动。
- 插值占位符：`{:name}`。拼接句必须改成占位符：
  ```php
  // 改前
  'The code will expire in ' . $min . ' minutes'
  // 改后
  t('The code will expire in {:min} minutes', ['min' => $min])
  ```
  JS 同理：`n + ' items in total'` → `t('{:n} items in total', { n: n })`。
- JS 侧的全局 `t()` 在 `app.js` 顶部（`window.t`），别的 .js 文件直接用。
- Vue 侧：`import { useT } from '相对路径/composables/useT.js'`，`const { t } = useT()`。

## 词典按域分（插件 / 皮肤有自己的 i18n/）

- 核心词典在 `locales/`；插件词典在 `plugins/<slug>/i18n/`，皮肤词典在 `skins/<slug>/i18n/`，
  每个语言一份 `<lang>.json`（清单见 `locales/languages.php`），键同样是英文原文。
- 运行时按「核心优先、扩展补缺」合并（`src/I18n.php` 的 `loadLocale()`），前端词典
  `/i18n/<lang>` 同样合并。所以插件/皮肤里用核心已有的键（`t('Save')` 这类）不用自己备译文，
  只有核心没有的键才进自己的 `i18n/`。
- 插件清单的 `description`、`specialPages.title` 和皮肤清单的 `description` 渲染时也过 `t()`，
  想翻译就在各自 `i18n/` 里放同键译文；没翻译就原样显示英文键。
- 生成与校验都按域走：`php docs-internal/i18n/extract.php` 生成各域的 en.json（母键 identity）；
  `--check` 校验每个域每个语言键齐全、占位符一致。新增 t() 调用后先跑生成再补翻译，提交前 `--check` 必须全绿。
  补翻译走「待补文件」：把只含新键的 `<语言>.json` 放一个目录里，
  `php docs-internal/i18n/merge-pending.php <目录> [词典目录]` 合并（只新增不改已有），跑完删目录。
- 动态键（值不经过 t('...') 字面量的）登记在 `extract.php` 的 `collectDynamicKeys()`：
  登录方式、恢复方式、活动日志动作、家庭关系/解除原因（`FamilyHelper`）、2FA 因素标签、
  Vue 的 label prop 默认值、t($var) 兜底键。新加这类常量记得去那里登记，否则 --check 会当成悬空或缺条。
- 站点语言设置：`config/installed.php` 的 `app.language_default`（默认语言）和
  `app.language_allow_switch`（关掉后固定默认语言，选择器、`?lang=`、账号偏好全停），
  安装向导的「站点」一步可以选；`.env` 的 `APP_LANGUAGE` 也能临时覆盖默认语言。

## 语言怎么切（2026-11-08 改版）

- 头部选择器（`templates/_lang_picker.php`）：**无 JS 就是去 `/special/lang` 的普通链接**；
  有 JS 才升级成下拉面板，选项 href 由 app.js 在面板打开时现场生成 `?lang=` 链接。
  **页面源码里不许出现 `?lang=` 链接**（移动端抽屉也一样走 `/special/lang`）——
  爬虫不跑 JS，服务端渲染出 25 个 `?lang=` 链接等于把同一页按语言分裂收录。
  带了 `?lang=` 的地址布局里已有 canonical 兜底，但别再新增这种链接。
- `/special/lang`（`src/Special/Lang.php`）：列出注册表全部语言，选中走 `?select=<slug>&rt=<来源页>`，
  `I18n::applyChoice()` 落 cookie（登录态写账号偏好），303 回来源页。
  `rt` 只收本站路径（防开放重定向）。站长关掉切换时这个页只显示固定语言的说明。
- `?lang=` 参数本身保留（分享/兼容旧链接），但只作为入口动作，不再往任何模板里输出。

## 什么要包，什么不包

**要包**（用户看得见的）：
- 模板 HTML 正文、按钮文字、标题、`placeholder`、`title` / `aria-label` 属性、`<option>` 文字
- 控制器里 `Session::flash('error'/'success', ...)` 的消息
- JSON 响应里的 `'message'` / `'error'` 值
- JS 里的 toast / alert / confirm 文案、校验提示、按钮默认值
- 展示点上的**标签常量**：登录方式名、恢复方式名、OAuth scope 描述这类 —— 在「输出给页面」的那一行包
  `t($label)`，常量定义本身不动（键=英文原文）。

**不包**（保持中文 / 不动）：
- `admin/` 模板、`admin_layout.php`、`admin.js`、AdminController —— 管理后台保持中文
- `public/nomen-install/`（安装向导）—— 一次性安装工具，不走多语言；extract 的扫描目录也没包含它
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
