<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { onMounted, ref } from 'vue'
import Icon from '../../components/Icon.vue'
import { toast } from '../../lib/toast.js'
import { useT } from '../../composables/useT.js'

const { t } = useT()

const props = defineProps({
  csrf: { type: String, default: '' },
  recoveryMode: { type: Boolean, default: false },
  labelEnable: { type: String, default: 'Enable TOTP' },
  labelDisable: { type: String, default: 'Disable TOTP' },
  labelEnabled: { type: String, default: 'TOTP enabled' },
  labelNotEnabled: { type: String, default: 'TOTP isn\'t enabled yet' },
  labelVerify: { type: String, default: 'Verify and enable' },
  urls: { type: Object, default: () => ({}) },
})

const urls = {
  status: props.urls?.status || '/2fa/totp/status',
  setup: props.urls?.setup || '/2fa/totp/setup',
  verify: props.urls?.verify || '/2fa/totp/verify',
  disable: props.urls?.disable || '/2fa/totp/disable',
}

const enabled = ref(false)
const setupOpen = ref(false)
const setupLoading = ref(false)
const verifying = ref(false)
const secret = ref('')
const qr = ref(null)
const code = ref('')
const disableBtn = ref(null)

// 二维码由 totp-qr 插件提供，缺席时只显示密钥、手动输入
function renderQr(uri) {
  const renderer = window.NomenQr
  if (!renderer || typeof renderer.render !== 'function') return null
  try {
    return renderer.render(uri)
  } catch (err) {
    return null
  }
}

async function loadStatus() {
  try {
    const response = await fetch(urls.status, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    const data = await response.json()
    if (data.success) enabled.value = !!data.enabled
  } catch (err) {
    // 状态拿不到就保持当前显示，不打扰
  }
}

onMounted(loadStatus)

async function startSetup() {
  setupLoading.value = true
  try {
    const response = await fetch(urls.setup, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    const data = await response.json()
    if (!data.success) {
      toast(t('Load failed'), 'error')
      return
    }
    if (data.has_totp) {
      toast(t('TOTP enabled'), 'info')
      loadStatus()
      return
    }
    setupOpen.value = true
    secret.value = data.secret || ''
    qr.value = data.uri ? renderQr(data.uri) : null
  } catch (err) {
    toast(t('Network error'), 'error')
  } finally {
    setupLoading.value = false
  }
}

function cancelSetup() {
  setupOpen.value = false
  code.value = ''
}

async function verifySetup() {
  const value = code.value.trim()
  if (!value || value.length !== 6) {
    toast(t('Enter the 6-digit verification code'), 'warning')
    return
  }

  verifying.value = true
  try {
    const body = new URLSearchParams()
    body.set('_csrf', props.csrf)
    body.set('code', value)
    const response = await fetch(urls.verify, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body.toString(),
    })
    const data = await response.json()
    if (data.success) {
      toast(t('TOTP enabled'), 'success')
      setupOpen.value = false
      code.value = ''
      enabled.value = true
      // TOTP 启用时自动生成的恢复码在这里补展示，用户必须当场保存
      if (data.recovery_codes && data.recovery_codes.length) {
        window.dispatchEvent(new CustomEvent('nomen:recovery-codes', { detail: data.recovery_codes }))
      }
    } else {
      toast(data.message, 'error')
    }
  } catch (err) {
    toast(t('Request failed'), 'error')
  } finally {
    verifying.value = false
  }
}

function disableTotp() {
  if (!window.Nomen || typeof window.Nomen.prompt !== 'function') return

  window.Nomen.prompt(t('Disable TOTP'), props.recoveryMode
    ? t('No password needed in recovery mode — just confirm:')
    : t('Enter the current password to disable TOTP:'), {
    confirmText: t('Disable'),
    cancelText: t('Cancel'),
    danger: true,
    inputType: 'password',
    placeholder: props.recoveryMode ? t('Can be left empty in recovery mode') : t('Current password'),
    required: !props.recoveryMode,
  }).then(async (password) => {
    if (password === null) return

    const body = new URLSearchParams()
    body.set('_csrf', props.csrf)
    body.set('password', password)
    try {
      const response = await fetch(urls.disable, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: body.toString(),
      })
      const data = await response.json()
      if (data.success) {
        toast(t('TOTP disabled'), 'success')
        enabled.value = false
        setupOpen.value = false
      } else {
        toast(data.message, 'error')
      }
    } catch (err) {
      toast(t('Network error'), 'error')
    }
  })
}
</script>

<template>
  <div>
    <div v-if="!enabled">
      <p class="text-muted" style="margin-bottom:16px;">{{ t(labelNotEnabled) }}</p>
      <button type="button" class="btn btn-primary" :disabled="setupLoading" @click="startSetup">{{ t(labelEnable) }}</button>
    </div>
    <div v-else>
      <p style="margin-bottom:12px;"><Icon name="check" extra="icon--success" /> <span style="color:var(--color-success);font-weight:600;">{{ t(labelEnabled) }}</span></p>
      <button ref="disableBtn" type="button" class="btn btn-sm btn-danger-outline" @click="disableTotp">{{ t(labelDisable) }}</button>
    </div>

    <div v-show="setupOpen" style="margin-top:16px;">
      <div class="form-group">
        <label>{{ t('1. Add the account in your authenticator app') }}</label>
        <p class="form-hint">{{ t('Enter the key manually or scan the QR code:') }}</p>
        <div style="text-align:center;margin:16px 0;">
          <div
            style="width:180px;height:180px;margin:0 auto;background:var(--color-bg-tertiary);border-radius:12px;display:flex;align-items:center;justify-content:center;color:var(--color-text-subtle);font-size:13px;"
          >
            <svg
              v-if="qr"
              :viewBox="`0 0 ${qr.size + 8} ${qr.size + 8}`"
              style="width:180px;height:180px;border-radius:8px;display:block;"
              shape-rendering="crispEdges"
              role="img"
              :aria-label="t('TOTP QR code')"
            >
              <rect :width="qr.size + 8" :height="qr.size + 8" fill="#fff" />
              <path :d="qr.path" fill="#000" />
            </svg>
            <span v-else>{{ t('Couldn\'t load the QR code — enter the key below manually') }}</span>
          </div>
        </div>
        <div style="background:var(--color-bg-tertiary);border-radius:8px;padding:12px;text-align:center;font-family:var(--font-mono);font-size:14px;word-break:break-all;">{{ secret }}</div>
        <p class="form-hint" style="margin-top:8px;text-align:center;">{{ t('The key is case-sensitive — enter it in full') }}</p>
      </div>
      <div class="form-group">
        <label>{{ t('2. Enter the 6-digit code from your authenticator app') }}</label>
        <div class="input-with-button">
          <input v-model="code" type="text" :placeholder="t('Enter the 6-digit verification code')" maxlength="6" autocomplete="off">
          <button type="button" class="btn btn-primary" :disabled="verifying" @click="verifySetup">{{ verifying ? t('Verifying...') : t(labelVerify) }}</button>
        </div>
      </div>
      <button type="button" class="btn btn-sm btn-ghost" style="margin-top:8px;" @click="cancelSetup">{{ t('Cancel') }}</button>
    </div>
  </div>
</template>
