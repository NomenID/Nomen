/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

import { createApp } from 'vue'
import LoginApp from '../pages/login/LoginApp.vue'

const mountEl = document.getElementById('login-app')
const propsEl = document.getElementById('login-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('登录页 props 解析失败', err)
  }
  createApp(LoginApp, props).mount(mountEl)
}
