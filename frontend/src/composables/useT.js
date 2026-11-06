// 多语言：词典由布局挂的 /i18n/{语言}.js 注入到 window.NOMEN_I18N（键是简体中文原文），
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
    return text
  }

  return { t }
}
