/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

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
