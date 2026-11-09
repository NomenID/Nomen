<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
import { computed, onMounted, ref } from 'vue'
import { toast } from '../../lib/toast.js'
import { useT } from '../../composables/useT.js'

const { t } = useT()

const props = defineProps({
  csrf: { type: String, default: '' },
  keys: { type: Array, default: () => [] },
  urls: { type: Object, default: () => ({}) },
})

const urls = {
  list: props.urls?.list || '/settings/temp-keys',
  create: props.urls?.create || '/settings/temp-keys',
  remove: props.urls?.remove || '/settings/temp-keys/delete',
  generate: props.urls?.generate || '/settings/temp-keys/generate',
}

const keys = ref(props.keys.map(normalizeKey))
const formOpen = ref(false)
const label = ref('')
const value = ref('')
const valueReadonly = ref(false)
const expires = ref('')
const saving = ref(false)

function normalizeKey(key) {
  return {
    id: Number(key.id),
    label: String(key.label || ''),
    expires_at: String(key.expires_at || ''),
    created_at: String(key.created_at || ''),
    last_used_at: key.last_used_at ? String(key.last_used_at) : '',
    expired: new Date(String(key.expires_at).replace(' ', 'T') + 'Z') < new Date(),
  }
}

function formatTime(ts) {
  if (!ts) return t('Never used')
  const date = new Date(String(ts).replace(' ', 'T') + 'Z')
  return date.toLocaleString(window.NOMEN_LANG || 'zh-Hans')
}

async function loadKeys() {
  try {
    const response = await fetch(urls.list, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    const data = await response.json()
    if (data.success) keys.value = (data.keys || []).map(normalizeKey)
  } catch (err) {
    // 保持当前列表
  }
}

onMounted(loadKeys)

async function generateKey() {
  try {
    const response = await fetch(urls.generate, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    const data = await response.json()
    if (data.success) value.value = data.key
  } catch (err) {
    // 生成失败就让它手填
  }
}

async function openForm() {
  formOpen.value = true
  label.value = ''
  value.value = ''
  valueReadonly.value = false
  await generateKey()

  const now = new Date()
  now.setHours(now.getHours() + 12)
  expires.value = now.toISOString().slice(0, 19)
}

function cancelForm() {
  formOpen.value = false
}

async function save() {
  if (!label.value.trim() || !value.value.trim() || !expires.value) {
    toast(t('Please fill in all fields'), 'warning')
    return
  }

  saving.value = true
  const body = new URLSearchParams()
  body.set('_csrf', props.csrf)
  body.set('label', label.value.trim())
  body.set('key_value', value.value.trim())
  body.set('expires_at', expires.value.slice(0, 16).replace('T', ' '))

  try {
    const response = await fetch(urls.create, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body.toString(),
    })
    const data = await response.json()
    if (data.success) {
      toast(data.message, 'success')
      formOpen.value = false
      loadKeys()
    } else {
      toast(data.message, 'error')
    }
  } catch (err) {
    toast(t('Network error'), 'error')
  } finally {
    saving.value = false
  }
}

async function removeKey(item) {
  if (!window.Nomen || typeof window.Nomen.confirm !== 'function') return

  const confirmed = await window.Nomen.confirm(t('Delete temp key'), t('Delete this temp key?'), {
    danger: true,
    confirmText: t('Delete'),
    cancelText: t('Cancel'),
  })
  if (!confirmed) return

  const body = new URLSearchParams()
  body.set('_csrf', props.csrf)
  body.set('key_id', item.id)
  try {
    const response = await fetch(urls.remove, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body.toString(),
    })
    const data = await response.json()
    if (data.success) {
      toast(data.message, 'success')
      loadKeys()
    } else {
      toast(data.message, 'error')
    }
  } catch (err) {
    toast(t('Network error'), 'error')
  }
}
</script>

<template>
  <div style="margin-bottom:20px;">
    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;">
      <p v-if="!keys.length" class="text-muted" style="padding:12px 0;">{{ t('No temp keys yet') }}</p>
      <div v-for="item in keys" :key="item.id" class="device-item">
        <div class="device-item__info">
          <div class="device-item__name">
            <span :style="item.expired ? 'text-decoration:line-through;' : ''">{{ item.label || t('Unnamed key') }}</span>
            <span v-if="item.expired" class="badge" style="margin-left:8px;font-size:11px;background:#eee;color:#999;">{{ t('Expired') }}</span>
          </div>
          <div class="device-item__meta">
            {{ t('Expires: {:expires} · Created: {:created}', { expires: formatTime(item.expires_at), created: formatTime(item.created_at) }) }}{{ item.last_used_at ? t(' · Last used: {:used}', { used: formatTime(item.last_used_at) }) : '' }}
          </div>
        </div>
        <button type="button" class="btn btn-sm btn-danger-outline" @click="removeKey(item)">{{ t('Delete') }}</button>
      </div>
    </div>
    <button v-show="!formOpen" type="button" class="btn btn-secondary" @click="openForm">{{ t('Create temp key') }}</button>
  </div>

  <div v-show="formOpen">
    <div class="form-group">
      <label for="temp-key-label">{{ t('Key label') }}</label>
      <input id="temp-key-label" v-model="label" type="text" :placeholder="t('e.g. School computer')" maxlength="50">
    </div>
    <div class="form-group">
      <label for="temp-key-value">{{ t('Key value') }}</label>
      <div style="display:flex;gap:8px;">
        <input id="temp-key-value" v-model="value" type="text" :placeholder="t('Auto-generate or set your own')" maxlength="64">
        <button type="button" class="btn btn-sm btn-secondary" @click="generateKey">{{ t('Generate') }}</button>
      </div>
      <small class="form-hint">{{ t('At least 10 characters with letters and digits; a 16-character random key is generated by default') }}</small>
    </div>
    <div class="form-group">
      <label for="temp-key-expires">{{ t('Expires at') }}</label>
      <input id="temp-key-expires" v-model="expires" type="datetime-local" step="1">
      <small class="form-hint">{{ t('Up to 14 days') }}</small>
    </div>
    <div style="display:flex;gap:8px;">
      <button type="button" class="btn btn-primary" :disabled="saving" @click="save">{{ saving ? t('Saving...') : t('Save') }}</button>
      <button type="button" class="btn btn-secondary" @click="cancelForm">{{ t('Cancel') }}</button>
    </div>
  </div>
</template>
