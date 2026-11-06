import { createApp } from 'vue'
import TwoFactorApp from '../pages/login/TwoFactorApp.vue'

const mountEl = document.getElementById('twofa-app')
const propsEl = document.getElementById('twofa-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('2FA props 解析失败', err)
  }
  createApp(TwoFactorApp, props).mount(mountEl)
}
