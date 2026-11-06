import { createApp } from 'vue'
import FamilyApp from '../pages/family/FamilyApp.vue'

const mountEl = document.getElementById('family-app')
const propsEl = document.getElementById('family-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('家庭页 props 解析失败', err)
  }
  createApp(FamilyApp, props).mount(mountEl)
}
