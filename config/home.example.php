<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

/**
 * 自定义首页示例。
 *
 * 用法：复制成 config/home-<名字>.php，然后在后台「首页」里选它。
 * 这里输出的是页面主体，头部、页脚和样式由当前皮肤提供。
 * 可用变量：$home（首页设置）、$brand（站点信息）。
 */
?>
<section style="max-width: 820px; margin: 0 auto; padding: 64px 24px;">
    <h1 style="margin: 0 0 12px; font-size: 2.2rem; line-height: 1.2;"><?= htmlspecialchars((string)$brand['name']) ?></h1>
    <p style="margin: 0 0 28px; color: var(--color-text-muted); line-height: 1.7;">
        这是 config 下的自定义首页。改这个文件就能换版式，不用动皮肤。
    </p>
    <p>
        <a class="btn btn-primary" href="/login">登录</a>
        <a class="btn btn-ghost" href="<?= htmlspecialchars((string)$brand['base_url']) ?>/special/version">版本信息</a>
    </p>
</section>
