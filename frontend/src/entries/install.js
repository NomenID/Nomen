import { createApp } from 'vue'
import InstallApp from '../pages/install/InstallApp.vue'
import '../pages/install/install.css'

const mountEl = document.getElementById('install-app')
const propsEl = document.getElementById('install-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('安装向导 props 解析失败', err)
  }
  createApp(InstallApp, props).mount(mountEl)
}
