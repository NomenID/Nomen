<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

/**
 * 只登记「这个页面要首屏加载层」。
 *
 * 真正的加载层由 layout.body.start 钩子输出到 body 最前面（见 Loading::html），
 * 这样它和 HTML 一起绘制，不会等 JS 才出现；模板里不再写任何加载层标记。
 */
\Nomen\Src\Loading::enable();
