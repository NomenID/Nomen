import { createApp } from 'vue'
import InstallApp from '../pages/install/InstallApp.vue'
import { setI18n } from '../pages/install/i18n'
import '../pages/install/install.css'

const mountEl = document.getElementById('install-app')
const propsEl = document.getElementById('install-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('Failed to parse the install wizard props', err)
  }
  setI18n(props.lang || 'en', window.__NOMEN_INSTALL_I18N__ || {})
  createApp(InstallApp, props).mount(mountEl)
}
