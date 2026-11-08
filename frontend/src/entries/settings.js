/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

import { createApp } from 'vue'
import SettingsApp from '../pages/settings/SettingsApp.vue'

const mountEl = document.getElementById('settings-app')
const propsEl = document.getElementById('settings-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('设置页 props 解析失败', err)
  }
  createApp(SettingsApp, props).mount(mountEl)
}
