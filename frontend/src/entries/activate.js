import { createApp } from 'vue'
import ActivateApp from '../pages/activate/ActivateApp.vue'

const mountEl = document.getElementById('activate-app')
const propsEl = document.getElementById('activate-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('激活页 props 解析失败', err)
  }
  createApp(ActivateApp, props).mount(mountEl)
}
