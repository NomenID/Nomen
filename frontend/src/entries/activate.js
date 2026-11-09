/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

import { createApp } from 'vue'
import ActivateApp from '../pages/activate/ActivateApp.vue'

const mountEl = document.getElementById('activate-app')
const propsEl = document.getElementById('activate-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('激活页 props 解析失败', err)
  }
  createApp(ActivateApp, props).mount(mountEl)
}
