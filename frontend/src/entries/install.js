/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

import { createApp } from 'vue'
import InstallApp from '../pages/install/InstallApp.vue'
import { setI18n } from '../pages/install/i18n'
import '../pages/install/install.css'

const mountEl = document.getElementById('install-app')
const propsEl = document.getElementById('install-props')

if (mountEl && propsEl) {
  let props = {}
  try {
    props = JSON.parse(propsEl.textContent || '{}')
  } catch (err) {
    console.error('Failed to parse the install wizard props', err)
  }
  setI18n(props.lang || 'en', window.__NOMEN_INSTALL_I18N__ || {})
  createApp(InstallApp, props).mount(mountEl)
}
