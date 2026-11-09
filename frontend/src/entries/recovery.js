/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

import { createApp } from 'vue'
import RecoveryApp from '../pages/recovery/RecoveryApp.vue'

const mountEl = document.getElementById('recovery-app')
const propsEl = document.getElementById('recovery-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('恢复申诉页 props 解析失败', err)
  }
  createApp(RecoveryApp, props).mount(mountEl)
}
