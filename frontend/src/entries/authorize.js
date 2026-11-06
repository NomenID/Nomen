import { createApp } from 'vue'
import AuthorizeApp from '../pages/authorize/AuthorizeApp.vue'

const mountEl = document.getElementById('authorize-app')
const propsEl = document.getElementById('authorize-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('授权页 props 解析失败', err)
  }
  createApp(AuthorizeApp, props).mount(mountEl)
}
