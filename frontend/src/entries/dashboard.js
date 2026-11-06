import { createApp } from 'vue'
import DashboardApp from '../pages/dashboard/DashboardApp.vue'

const mountEl = document.getElementById('dashboard-app')
const propsEl = document.getElementById('dashboard-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('仪表盘 props 解析失败', err)
  }
  createApp(DashboardApp, props).mount(mountEl)
}
