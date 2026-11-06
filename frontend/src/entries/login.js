import { createApp } from 'vue'
import LoginApp from '../pages/login/LoginApp.vue'

const mountEl = document.getElementById('login-app')
const propsEl = document.getElementById('login-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('登录页 props 解析失败', err)
  }
  createApp(LoginApp, props).mount(mountEl)
}
