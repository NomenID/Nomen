import { createApp } from 'vue'
import RegisterApp from '../pages/register/RegisterApp.vue'

const mountEl = document.getElementById('register-app')
const propsEl = document.getElementById('register-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('注册页 props 解析失败', err)
  }
  createApp(RegisterApp, props).mount(mountEl)
}
