/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

const ID_KEY = 'nomen_login_id_v1'
const HIST_KEY = 'nomen_login_hist_v1'
const HANDOFF_COOKIE = 'nomen_login_ok'
const HIST_MAX = 12
const ORDER = ['password', 'email_code', 'biometric']

function readJson(key, fallback) {
  try {
    const raw = localStorage.getItem(key)
    return raw ? JSON.parse(raw) : fallback
  } catch (err) {
    return fallback
  }
}

function writeJson(key, value) {
  try {
    localStorage.setItem(key, JSON.stringify(value))
  } catch (err) {
    // 无痕模式或存储被禁用：记不住就退回默认行为，不影响登录
  }
}

function readCookie(name) {
  const parts = ('; ' + document.cookie).split('; ' + name + '=')
  return parts.length === 2 ? parts.pop().split(';').shift() : ''
}

function base64urlToText(value) {
  const s = String(value || '').replace(/-/g, '+').replace(/_/g, '/')
  const padded = s + '='.repeat((4 - (s.length % 4)) % 4)
  const binary = atob(padded)
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)
  return new TextDecoder().decode(bytes)
}

export function consumeHandoff() {
  const raw = readCookie(HANDOFF_COOKIE)
  if (!raw) return

  document.cookie = HANDOFF_COOKIE + '=; Max-Age=0; path=/'

  let data = null
  try {
    data = JSON.parse(base64urlToText(raw))
  } catch (err) {
    return
  }
  if (!data || !data.u) return

  writeJson(ID_KEY, { u: data.u, e: data.e || '' })

  let hist = readJson(HIST_KEY, [])
  if (!Array.isArray(hist)) hist = []
  hist.push({ u: data.u, e: data.e || '', m: data.m || 'password', t: Math.floor(Date.now() / 1000) })
  writeJson(HIST_KEY, hist.slice(-HIST_MAX))
}

export function rememberedAccount() {
  return readJson(ID_KEY, null)
}

function firstAvailable(available) {
  for (const method of ORDER) {
    if (available(method)) return method
  }
  return null
}

export function recommendMethod(available) {
  const fallback = firstAvailable(available)
  if (!fallback) return null

  const id = rememberedAccount()
  if (!id || !id.u) return fallback

  const hist = readJson(HIST_KEY, [])
  if (!Array.isArray(hist)) return fallback

  const mine = []
  for (const h of hist) {
    if (h && (h.u === id.u || (id.e && h.e === id.e))) mine.push(h.m)
  }
  if (mine.length < 2) return fallback

  const last = mine[mine.length - 1]
  if (mine[mine.length - 2] !== last) return fallback

  return available(last) ? last : fallback
}

export function pickLoginMethod(tried, preferred, available) {
  if (preferred && !tried[preferred] && available(preferred)) return preferred

  for (const method of ORDER) {
    if (!tried[method] && available(method)) return method
  }
  return firstAvailable(available)
}
