/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

import { t } from './i18n'

let csrf = ''
let lang = ''

export function setCsrf(token) {
  csrf = token || ''
}

export function setLang(value) {
  lang = value || ''
}

export async function request(action, payload, options = {}) {
  const method = options.method || 'POST'
  const headers = { 'X-CSRF-Token': csrf }
  if (lang) {
    headers['X-Nomen-Lang'] = lang
  }
  const init = {
    method,
    credentials: 'same-origin',
    headers,
  }

  if (method !== 'GET' && payload !== undefined && payload !== null) {
    if (payload instanceof FormData) {
      init.body = payload
    } else {
      init.headers['Content-Type'] = 'application/json'
      init.body = JSON.stringify(payload)
    }
  }

  let response
  try {
    response = await fetch(`/nomen-install/api.php?action=${encodeURIComponent(action)}${options.query || ''}`, init)
  } catch (err) {
    throw new Error(t('Could not reach the server. Check the network and try again.'))
  }

  let data = {}
  try {
    data = await response.json()
  } catch (err) {
    data = {}
  }

  if (!response.ok || data.error) {
    const error = new Error(data.error || t('Request failed ({:code}).', { code: response.status }))
    error.status = response.status
    error.step = data.step || ''
    throw error
  }

  return data
}

/** 取一份语言词典，顺便把选择存进服务器会话。 */
export async function fetchI18n(target) {
  const query = target ? '&set=' + encodeURIComponent(target) : ''
  return request('i18n', null, { method: 'GET', query })
}
