/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

import { createApp } from 'vue'
import FamilyApp from '../pages/family/FamilyApp.vue'

const mountEl = document.getElementById('family-app')
const propsEl = document.getElementById('family-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('家庭页 props 解析失败', err)
  }
  createApp(FamilyApp, props).mount(mountEl)
}
