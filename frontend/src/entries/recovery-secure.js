/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

import { createApp } from 'vue'
import RecoverySecureApp from '../pages/recovery/RecoverySecureApp.vue'

const mountEl = document.getElementById('recovery-secure-app')
const propsEl = document.getElementById('recovery-secure-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('恢复修复页 props 解析失败', err)
  }
  createApp(RecoverySecureApp, props).mount(mountEl)
}
