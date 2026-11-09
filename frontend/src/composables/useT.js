/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

// 多语言：词典由布局挂的 /i18n/{语言}.js 注入到 window.NOMEN_I18N（母键 = 英文原句），
// 和 app.js 里的 t() 是同一套。词条缺失或没挂词典时原样返回键。
export function useT() {
  const dict = () => (typeof window !== 'undefined' && window.NOMEN_I18N) || null

  function t(key, params) {
    const dictValue = dict() ? dict()[key] : null
    let text = typeof dictValue === 'string' && dictValue !== '' ? dictValue : key
    if (params) {
      for (const name of Object.keys(params)) {
        text = text.split('{:' + name + '}').join(String(params[name]))
      }
    }
    if (text.indexOf('{:sitename}') !== -1 && !(params && params.sitename)) {
      const sn = (typeof window !== 'undefined' && window.NOMEN_SITENAME) || 'Nomen'
      text = text.split('{:sitename}').join(sn)
    }
    return text
  }

  return { t }
}
