import { createApp } from 'vue'
import NomenLanding from '../pages/landing-nomen/NomenLanding.vue'

const mountEl = document.getElementById('landing-app')
const propsEl = document.getElementById('landing-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('首页 props 解析失败', err)
  }
  createApp(NomenLanding, props).mount(mountEl)
}
