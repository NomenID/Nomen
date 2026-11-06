import { createApp } from 'vue'
import RecoveryApp from '../pages/recovery/RecoveryApp.vue'

const mountEl = document.getElementById('recovery-app')
const propsEl = document.getElementById('recovery-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('恢复申诉页 props 解析失败', err)
  }
  createApp(RecoveryApp, props).mount(mountEl)
}
