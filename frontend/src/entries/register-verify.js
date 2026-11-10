/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

import { createApp } from 'vue'
import RegisterVerifyApp from '../pages/register/RegisterVerifyApp.vue'

const mountEl = document.getElementById('register-verify-app')
const propsEl = document.getElementById('register-verify-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('注册验证页 props 解析失败', err)
  }
  createApp(RegisterVerifyApp, props).mount(mountEl)
}
