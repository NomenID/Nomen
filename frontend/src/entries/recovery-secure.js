import { createApp } from 'vue'
import RecoverySecureApp from '../pages/recovery/RecoverySecureApp.vue'

const mountEl = document.getElementById('recovery-secure-app')
const propsEl = document.getElementById('recovery-secure-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('恢复修复页 props 解析失败', err)
  }
  createApp(RecoverySecureApp, props).mount(mountEl)
}
