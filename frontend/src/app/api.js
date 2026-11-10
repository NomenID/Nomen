/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

// 应用区 SPA 的请求层。所有页面数据与动作都走这里：
// - 统一带 X-Requested-With（服务端据此回 JSON 而不是 redirect+flash）
// - 响应不是 JSON（如会话过期被 302 到登录页）时整页跟过去
let csrfToken = ''

export function setCsrf(token) {
  csrfToken = token
}

export function getCsrf() {
  return csrfToken
}

async function handle(response) {
  const type = response.headers.get('content-type') || ''
  if (!type.includes('application/json')) {
    // 被服务端 302 到了普通页面（登录页 / 激活页 / 恢复拦截）——整页跟过去
    window.location.href = response.url || window.location.href
    return new Promise(() => {})
  }
  return response.json()
}

export async function apiGet(path) {
  const response = await fetch(path, {
    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    credentials: 'same-origin',
  })
  return handle(response)
}

export async function apiPost(path, params = {}) {
  const body = new URLSearchParams()
  body.set('_csrf', csrfToken)
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null) body.set(key, String(value))
  }
  const response = await fetch(path, {
    method: 'POST',
    body,
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-Requested-With': 'XMLHttpRequest',
      Accept: 'application/json',
    },
  })
  return handle(response)
}

// 带文件（图标等）的提交：FormData 自己带 boundary，不能再设 Content-Type
export async function apiPostForm(path, formData) {
  formData.set('_csrf', csrfToken)
  const response = await fetch(path, {
    method: 'POST',
    body: formData,
    credentials: 'same-origin',
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      Accept: 'application/json',
    },
  })
  return handle(response)
}
