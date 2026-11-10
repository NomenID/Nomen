/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

import { reactive } from 'vue'

// 安装向导的多语言：词典由 index.php 内嵌（window.__NOMEN_INSTALL_I18N__），
// 切语言走 api.php?action=i18n 再整页刷新。母键 = 英文原句，与核心词典同一套。
export const i18n = reactive({ locale: 'en', dict: {} })

export function setI18n(locale, dict) {
  i18n.locale = locale || 'en'
  i18n.dict = dict && typeof dict === 'object' ? dict : {}
}

export function t(key, params) {
  let text = i18n.dict[key] || key
  if (params) {
    for (const name of Object.keys(params)) {
      text = text.split('{:' + name + '}').join(String(params[name]))
    }
  }
  return text
}
