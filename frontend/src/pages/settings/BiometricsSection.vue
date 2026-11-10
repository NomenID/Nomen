<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { onMounted, ref } from 'vue'
import { bytesToBase64url, base64urlToBytes } from '../../lib/webauthn.js'
import { toast } from '../../lib/toast.js'
import { useT } from '../../composables/useT.js'

const { t } = useT()

const props = defineProps({
  csrf: { type: String, default: '' },
  urls: { type: Object, default: () => ({}) },
})

const urls = {
  list: props.urls?.list || '/settings/biometrics',
  remove: props.urls?.remove || '/settings/biometrics/delete',
  options: props.urls?.options || '/biometric/registration/options',
  verify: props.urls?.verify || '/biometric/registration/verify',
}

const supported = 'credentials' in navigator
const list = ref([])
const loading = ref(true)
const formOpen = ref(false)
const registering = ref(false)
const registerText = ref(t('Start binding'))
const status = ref('')

function deviceTypeText(type) {
  return {
    platform: t('Built-in sensor (fingerprint / face)'),
    usb_key: t('USB security key'),
    nfc_card: t('NFC card'),
    ble_device: t('BLE device'),
  }[type] || t('Unknown device')
}

function formatTime(ts) {
  if (!ts) return t('Unknown')
  const date = new Date(String(ts).replace(' ', 'T') + 'Z')
  return date.toLocaleString(window.NOMEN_LANG || 'zh-Hans')
}

async function loadList() {
  loading.value = true
  try {
    const response = await fetch(urls.list, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    const data = await response.json()
    if (data.success) list.value = data.credentials || []
  } catch (err) {
    // 保持空列表
  } finally {
    loading.value = false
  }
}

onMounted(loadList)

function openForm() {
  formOpen.value = true
  status.value = ''
}

function cancelForm() {
  formOpen.value = false
  status.value = ''
}

async function removeItem(item) {
  if (!window.Nomen || typeof window.Nomen.confirm !== 'function') return

  const confirmed = await window.Nomen.confirm(t('Delete biometric'), t('Delete this biometric credential? You\'ll need to bind again before using biometric sign-in.'), {
    danger: true,
    confirmText: t('Delete'),
    cancelText: t('Cancel'),
  })
  if (!confirmed) return

  const body = new URLSearchParams()
  body.set('_csrf', props.csrf)
  body.set('id', item.id)
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
      loadList()
    } else {
      toast(data.message, 'error')
    }
  } catch (err) {
    toast(t('Network error'), 'error')
  }
}

async function register() {
  if (registering.value) return
  registering.value = true
  registerText.value = t('Preparing...')
  status.value = t('Preparing binding...')

  try {
    const optionsResponse = await fetch(urls.options, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    const data = await optionsResponse.json()
    if (!data.success) {
      toast(data.message || t('Couldn\'t get options'), 'error')
      registerText.value = t('Start binding')
      status.value = ''
      registering.value = false
      return
    }

    const options = data.options
    options.publicKey.challenge = base64urlToBytes(options.publicKey.challenge)
    options.publicKey.user.id = base64urlToBytes(options.publicKey.user.id)

    registerText.value = t('Complete the biometric verification...')
    status.value = t('Calling system biometrics (fingerprint/face/PIN)...')

    const credential = await navigator.credentials.create({ publicKey: options.publicKey })
    if (!credential) {
      toast(t('Binding cancelled'), 'warning')
      registerText.value = t('Start binding')
      status.value = ''
      registering.value = false
      return
    }

    const response = credential.response
    const verifyResponse = await fetch(urls.verify, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({
        credential: {
          id: credential.id,
          type: credential.type,
          rawId: bytesToBase64url(credential.rawId),
          attestationObject: bytesToBase64url(response.attestationObject),
          clientDataJSON: bytesToBase64url(response.clientDataJSON),
          transports: response.getTransports ? response.getTransports() : [],
        },
      }),
    })
    const result = await verifyResponse.json()
    if (result.success) {
      toast(result.message, 'success')
      formOpen.value = false
      loadList()
    } else {
      toast(result.message || t('Binding failed'), 'error')
    }
  } catch (err) {
    toast(t('Binding failed: {:error}', { error: err.message || t('Unknown error') }), 'error')
  } finally {
    registering.value = false
    registerText.value = t('Start binding')
    status.value = ''
  }
}
</script>

<template>
  <div style="margin-bottom:20px;">
    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;">
      <p v-if="loading" class="text-muted" style="padding:12px 0;">{{ t('Loading...') }}</p>
      <p v-else-if="!list.length" class="text-muted" style="padding:12px 0;">{{ t('No biometric credentials') }}</p>
      <div v-for="item in list" :key="item.id" class="device-item">
        <div class="device-item__info">
          <div class="device-item__name">{{ deviceTypeText(item.device_type) }}</div>
          <div class="device-item__meta">{{ t('Created: {:time}', { time: formatTime(item.created_at) }) }}</div>
        </div>
        <button type="button" class="btn btn-sm btn-danger-outline" @click="removeItem(item)">{{ t('Delete') }}</button>
      </div>
    </div>
    <button v-if="supported" type="button" class="btn btn-secondary" @click="openForm">{{ t('Add biometric') }}</button>
  </div>

  <div v-show="formOpen">
    <div class="form-group">
      <label>{{ t('Bind biometrics') }}</label>
      <p class="form-hint">{{ t('Press the button below and your device will walk you through biometric verification (fingerprint, face, etc.)') }}</p>
    </div>
    <div style="display:flex;gap:8px;">
      <button type="button" class="btn btn-primary" :disabled="registering" @click="register">{{ registerText }}</button>
      <button type="button" class="btn btn-secondary" :disabled="registering" @click="cancelForm">{{ t('Cancel') }}</button>
    </div>
    <div style="margin-top:12px;font-size:13px;color:var(--color-text-subtle);">{{ status }}</div>
  </div>
</template>
