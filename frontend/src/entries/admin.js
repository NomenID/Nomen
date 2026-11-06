import { createApp } from 'vue'
import '../app/nomen.js'
import AdminRoot from '../admin/AdminRoot.vue'
import { createAdminRouter } from '../admin/router'

const mountEl = document.getElementById('admin-root')
const propsEl = document.getElementById('admin-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('管理后台 props 解析失败', err)
  }

  const app = createApp(AdminRoot, props)
  app.use(createAdminRouter(props.brand?.name || 'Nomen'))
  app.mount(mountEl)
}
