import { createApp } from 'vue'
import SettingsApp from '../pages/settings/SettingsApp.vue'

const mountEl = document.getElementById('settings-app')
const propsEl = document.getElementById('settings-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('设置页 props 解析失败', err)
  }
  createApp(SettingsApp, props).mount(mountEl)
}
