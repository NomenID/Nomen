/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

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
