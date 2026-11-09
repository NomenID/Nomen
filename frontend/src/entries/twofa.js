/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

import { createApp } from 'vue'
import TwoFactorApp from '../pages/login/TwoFactorApp.vue'

const mountEl = document.getElementById('twofa-app')
const propsEl = document.getElementById('twofa-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('2FA props 解析失败', err)
  }
  createApp(TwoFactorApp, props).mount(mountEl)
}
