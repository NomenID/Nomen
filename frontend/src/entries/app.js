/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

import { createApp } from 'vue'
import '../app/nomen.js'
import AppRoot from '../app/AppRoot.vue'
import { createAppRouter } from '../app/router'

const mountEl = document.getElementById('app-root')
const propsEl = document.getElementById('app-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('应用区 props 解析失败', err)
  }

  const app = createApp(AppRoot, props)
  app.use(createAppRouter())
  app.mount(mountEl)
}
