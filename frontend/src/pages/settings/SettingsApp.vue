<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import BaseAlert from '../../components/BaseAlert.vue'
import Icon from '../../components/Icon.vue'
import { toast } from '../../lib/toast.js'
import { isSvgFile, svgToPngFile, SVG_PNG_SIZE } from '../../lib/svg.js'
import { generateStrongPassword, passwordStrength, copyText } from '../../composables/usePasswordTools.js'
import { useT } from '../../composables/useT.js'
import BiometricsSection from './BiometricsSection.vue'
import EmailSetSection from './EmailSetSection.vue'
import RecoveryCodesSection from './RecoveryCodesSection.vue'
import TempKeysSection from './TempKeysSection.vue'
import TotpSection from './TotpSection.vue'

const { t } = useT()

const props = defineProps({
  csrf: { type: String, default: '' },
  features: { type: Object, default: () => ({ country: false, guardians: false }) },
  user: { type: Object, default: () => ({}) },
  emails: { type: Array, default: () => [] },
  maxEmails: { type: Number, default: 5 },
  verifiedEmails: { type: Array, default: () => [] },
  notifSettings: { type: Object, default: () => ({}) },
  locales: { type: Array, default: () => [] },
  currentLocale: { type: String, default: '' },
  skins: { type: Array, default: () => [] },
  currentSkin: { type: String, default: '' },
  activeTokens: { type: Array, default: () => [] },
  trustedDevices: { type: Array, default: () => [] },
  identities: { type: Array, default: () => [] },
  identityProviders: { type: Array, default: () => [] },
  urls: { type: Object, default: () => ({}) },
})

const urls = {
  avatar: props.urls?.avatar || '/settings/avatar',
  profile: props.urls?.profile || '/settings/profile',
  password: props.urls?.password || '/settings/password',
  sendResetCode: props.urls?.sendResetCode || '/settings/password/send-code',
  resetCode: props.urls?.resetCode || '/settings/password/reset-code',
  revokeDevice: props.urls?.revokeDevice || '/settings/devices/revoke',
  tempKeys: props.urls?.tempKeys || {},
}

const devices = ref(props.trustedDevices.map((device) => ({ ...device })))
const linkedIdentities = ref(props.identities.map((item) => ({ ...item })))
const unlinkedProviders = computed(() =>
  props.identityProviders.filter((provider) => !linkedIdentities.value.some((item) => item.provider_slug === provider.slug)),
)

async function unlinkIdentity(item) {
  const ok = await window.Nomen.confirm(
    t('Unlink sign-in method'),
    t('After unlinking, you can no longer sign in with {:name}. Continue?', { name: item.provider_name }),
    { confirmText: t('Unlink'), cancelText: t('Cancel') },
  )
  if (!ok) return
  const body = new URLSearchParams()
  body.set('_csrf', props.csrf)
  body.set('provider_id', String(item.provider_id))
  const response = await fetch('/settings/identities/unlink', {
    method: 'POST',
    body,
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
  })
  const result = await response.json().catch(() => ({ success: false, message: t('Request failed') }))
  if (result.success) {
    linkedIdentities.value = linkedIdentities.value.filter((row) => row.provider_id !== item.provider_id)
    toast(result.message, 'success')
  } else {
    toast(result.message || t('Unlink failed'), 'error')
  }
}

const profile = reactive({
  display_name: props.user.display_name || '',
  bio: props.user.bio || '',
})
const passwordForm = reactive({
  current: '',
  next: '',
  confirm: '',
})
const resetForm = reactive({
  target: props.verifiedEmails.find((choice) => choice.is_primary)?.email || props.verifiedEmails[0]?.email || '',
  code: '',
  next: '',
  confirm: '',
})
const policy = ref(props.user.twoFactorPolicy || 'new_device')
const notif = reactive({
  system_important: !!props.notifSettings.notify_system_important,
  system_general: !!props.notifSettings.notify_system_general,
  system_interact: !!props.notifSettings.notify_system_interact,
})

const userInitial = computed(() => Array.from(props.user.displayName || props.user.username || '')[0]?.toUpperCase() || '?')

// ---- 头像 ----
const avatarUrl = ref(props.user.avatarUrl || '')
const avatarFile = ref(null)
const avatarFileName = ref('')
const avatarUploading = ref(false)
const avatarInput = ref(null)

async function onAvatarChange(event) {
  const file = event.target.files && event.target.files[0]
  if (!file) return
  if (!isSvgFile(file)) {
    useAvatarFile(file)
    return
  }
  try {
    const png = await svgToPngFile(file, SVG_PNG_SIZE)
    useAvatarFile(png)
    toast(t('SVG converted to a {:n}×{:n} PNG', { n: SVG_PNG_SIZE }), 'success')
  } catch (err) {
    if (avatarInput.value) avatarInput.value.value = ''
    toast(t('Can\'t convert this SVG — try a PNG or JPG'), 'error')
  }
}

function useAvatarFile(file) {
  avatarFile.value = file
  avatarFileName.value = file.name
}

async function uploadAvatar() {
  if (!avatarFile.value || avatarUploading.value) return
  avatarUploading.value = true

  const data = new FormData()
  data.append('avatar', avatarFile.value)
  data.append('_csrf', props.csrf)

  try {
    const response = await fetch(urls.avatar, {
      method: 'POST',
      body: data,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    const result = await response.json()
    if (result.success) {
      avatarUrl.value = result.avatar_url
      toast(result.message, 'success')
      avatarFile.value = null
      avatarFileName.value = ''
      if (avatarInput.value) avatarInput.value.value = ''
    } else {
      toast(result.message, 'error')
    }
  } catch (err) {
    toast(t('Network error'), 'error')
  } finally {
    avatarUploading.value = false
  }
}

// ---- 资料表单 ----
const profileSaving = ref(false)

async function saveProfile() {
  if (profileSaving.value) return
  profileSaving.value = true
  try {
    const data = new FormData()
    data.append('_csrf', props.csrf)
    data.append('display_name', profile.display_name)
    data.append('bio', profile.bio)
    const response = await fetch(urls.profile, {
      method: 'POST',
      body: data,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    const result = await response.json()
    toast(result.message || (result.success ? t('Saved') : t('Save failed')), result.success ? 'success' : 'error')
  } catch (err) {
    toast(err.message || t('Network error'), 'error')
  } finally {
    profileSaving.value = false
  }
}

// ---- 密码表单 ----
const passwordSaving = ref(false)
const passwordStrengthInfo = computed(() => passwordStrength(passwordForm.next))
const passwordStrengthText = computed(() => (passwordForm.next ? t('Strength: {:label}', { label: t(passwordStrengthInfo.value.label) }) : t('Password strength')))

async function generatePassword() {
  const password = generateStrongPassword(16)
  passwordForm.next = password
  passwordForm.confirm = password
  const ok = await copyText(password)
  toast(
    ok
      ? t('Strong password generated and copied. Paste it into your password manager, then clear the clipboard.')
      : t('Strong password generated. Select and copy it in the field, and clear your clipboard afterwards.'),
    ok ? 'success' : 'warning',
    6000,
  )
}

async function savePassword() {
  if (passwordSaving.value) return
  passwordSaving.value = true
  try {
    const data = new FormData()
    data.append('_csrf', props.csrf)
    data.append('current_password', passwordForm.current)
    data.append('new_password', passwordForm.next)
    data.append('confirm_password', passwordForm.confirm)
    const response = await fetch(urls.password, {
      method: 'POST',
      body: data,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    const result = await response.json()
    toast(result.message || (result.success ? t('Saved') : t('Save failed')), result.success ? 'success' : 'error')
    if (result.success) {
      passwordForm.current = ''
      passwordForm.next = ''
      passwordForm.confirm = ''
    }
  } catch (err) {
    toast(err.message || t('Network error'), 'error')
  } finally {
    passwordSaving.value = false
  }
}

// ---- 验证码重置密码 ----
const resetSending = ref(false)
const resetSaving = ref(false)
const resetCooldown = ref(0)
let resetTimer = null

async function sendResetCode() {
  if (resetSending.value || resetCooldown.value > 0) return
  resetSending.value = true

  const body = new URLSearchParams()
  body.set('_csrf', props.csrf)
  if (props.verifiedEmails.length > 1) body.set('send_to', resetForm.target)

  try {
    const response = await fetch(urls.sendResetCode, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body.toString(),
    })
    const result = await response.json()
    if (result.success) {
      toast(result.message, 'success')
      startResetCooldown(60)
    } else if (result.retry_after) {
      startResetCooldown(result.retry_after)
      toast(result.message, 'error')
    } else {
      toast(result.message, 'error')
    }
  } catch (err) {
    toast(t('Network error'), 'error')
  } finally {
    resetSending.value = false
  }
}

function startResetCooldown(seconds) {
  resetCooldown.value = Math.max(0, Number(seconds) || 0)
  if (resetTimer) clearInterval(resetTimer)
  resetTimer = setInterval(() => {
    resetCooldown.value -= 1
    if (resetCooldown.value <= 0) {
      clearInterval(resetTimer)
      resetTimer = null
      resetCooldown.value = 0
    }
  }, 1000)
}

async function submitReset() {
  const code = resetForm.code.trim()
  if (!code || !resetForm.next || !resetForm.confirm) {
    toast(t('Please fill in all fields'), 'warning')
    return
  }
  if (resetForm.next.length < 8 || !/[a-zA-Z]/.test(resetForm.next) || !/[0-9]/.test(resetForm.next)) {
    toast(t('8+ characters, letters and digits'), 'warning')
    return
  }
  if (resetForm.next !== resetForm.confirm) {
    toast(t('The two passwords don\'t match'), 'warning')
    return
  }

  resetSaving.value = true
  const body = new URLSearchParams()
  body.set('_csrf', props.csrf)
  body.set('reset_code', code)
  body.set('new_password', resetForm.next)
  body.set('confirm_password', resetForm.confirm)

  try {
    const response = await fetch(urls.resetCode, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body.toString(),
    })
    const result = await response.json()
    if (result.success) {
      toast(result.message, 'success')
      resetForm.code = ''
      resetForm.next = ''
      resetForm.confirm = ''
    } else {
      toast(result.message || t('Reset failed'), 'error')
    }
  } catch (err) {
    toast(t('Network error'), 'error')
  } finally {
    resetSaving.value = false
  }
}

// ---- 策略类表单（2FA / 快速登录 / 通知）----
async function postForm(url, params) {
  const body = new URLSearchParams()
  body.set('_csrf', props.csrf)
  for (const [key, value] of Object.entries(params)) body.set(key, value)
  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-Requested-With': 'XMLHttpRequest',
    },
    body: body.toString(),
  })
  return response.json()
}

const policySaving = ref(false)

async function savePolicy() {
  if (policySaving.value) return
  policySaving.value = true
  try {
    const result = await postForm('/settings/two-factor/policy', { policy: policy.value })
    toast(result.message || (result.success ? t('Saved') : t('Save failed')), result.success ? 'success' : 'error')
  } catch (err) {
    toast(t('Network error'), 'error')
  } finally {
    policySaving.value = false
  }
}

const notifSaving = ref(false)

async function saveNotif() {
  if (notifSaving.value) return
  notifSaving.value = true
  try {
    const result = await postForm('/settings/notifications', {
      notify_system_important: notif.system_important ? '1' : '',
      notify_system_general: notif.system_general ? '1' : '',
      notify_system_interact: notif.system_interact ? '1' : '',
    })
    toast(result.message || (result.success ? t('Saved') : t('Save failed')), result.success ? 'success' : 'error')
  } catch (err) {
    toast(t('Network error'), 'error')
  } finally {
    notifSaving.value = false
  }
}

// ---- 撤销信任设备 ----
async function revokeDevice(device) {
  if (!window.Nomen || typeof window.Nomen.confirm !== 'function') return

  const confirmed = await window.Nomen.confirm(t('Revoke device trust'), t('Revoke trust for device "{:name}"? It will need to verify again on its next sign-in.', { name: device.deviceName }), {
    danger: true,
    confirmText: t('Revoke'),
    cancelText: t('Cancel'),
  })
  if (!confirmed) return

  const body = new URLSearchParams()
  body.set('_csrf', props.csrf)
  body.set('device_id', device.deviceId)
  try {
    const response = await fetch(urls.revokeDevice, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body.toString(),
    })
    const result = await response.json()
    if (result.success) {
      toast(result.message, 'success')
      devices.value = devices.value.filter((item) => item.deviceId !== device.deviceId)
    } else {
      toast(result.message, 'error')
    }
  } catch (err) {
    toast(t('Network error'), 'error')
  }
}

// ---- 锚点定位 ----
onMounted(() => {
  const hash = window.location.hash
  if (!hash || hash.length < 2) return
  const target = document.getElementById(decodeURIComponent(hash.slice(1)))
  if (!target || !target.classList.contains('settings-section')) return
  target.classList.add('settings-section--flash')
  target.scrollIntoView({ behavior: 'smooth', block: 'start' })
  setTimeout(() => target.scrollIntoView({ behavior: 'smooth', block: 'start' }), 300)
})
</script>

<template>
  <div class="settings">
    <h1>{{ t('Account settings') }}</h1>

    <BaseAlert v-if="user.error" type="error" :message="user.error" />
    <BaseAlert v-if="user.success" type="success" :message="user.success" />

    <div class="settings-section" id="sec-avatar">
      <h2>{{ t('Avatar') }}</h2>
      <div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;">
        <div
          style="width:80px;height:80px;border-radius:50%;overflow:hidden;flex-shrink:0;background:#e9ecef;display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:600;color:#6c757d;"
        >
          <img v-if="avatarUrl" :src="avatarUrl" :alt="t('Avatar')" style="width:100%;height:100%;object-fit:cover;">
          <template v-else>{{ userInitial }}</template>
        </div>
        <div>
          <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <input ref="avatarInput" type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml" style="display:none;" @change="onAvatarChange">
            <button type="button" class="btn btn-secondary" @click="avatarInput?.click()">{{ t('Choose images') }}</button>
            <button v-show="avatarFile" type="button" class="btn btn-primary" :disabled="avatarUploading" @click="uploadAvatar">{{ avatarUploading ? t('Uploading...') : t('Upload avatar') }}</button>
            <span class="text-muted" style="font-size:13px;">{{ avatarFileName }}</span>
          </div>
          <small class="form-hint" style="margin-top:8px;">{{ t('JPG, PNG, GIF, WebP or SVG, up to 2MB; SVG is auto-converted to a 135×135 PNG. Avatars are cropped to a 135×135 square') }}</small>
        </div>
      </div>
    </div>

    <div class="settings-section" id="sec-profile">
      <h2>{{ t('Profile') }}</h2>
      <form class="settings-form" @submit.prevent="saveProfile">
        <div class="form-group">
          <label>{{ t('Username') }}</label>
          <input type="text" :value="user.username" disabled class="input-disabled">
          <small class="form-hint">{{ t('Username can\'t be changed') }}</small>
        </div>
        <div class="form-group">
          <label>{{ t('Primary email') }}</label>
          <template v-if="user.email">
            <input type="text" :value="user.email" disabled class="input-disabled">
            <small class="form-hint">{{ t('Used for notifications and account recovery. Change it in Email addresses below') }}</small>
          </template>
          <template v-else>
            <input type="text" :value="features.guardians ? t('Not set (mail goes to a parent\'s address)') : t('Not set')" disabled class="input-disabled">
            <small v-if="features.guardians" class="form-hint">{{ t('You can bind your own address in Email addresses below') }}</small>
          </template>
        </div>
        <div class="form-group">
          <label>{{ t('Date of birth') }}</label>
          <input type="text" :value="user.birthDateDisplay" disabled class="input-disabled">
        </div>
        <div class="form-group">
          <label>{{ t('Gender') }}</label>
          <input type="text" :value="user.genderDisplay" disabled class="input-disabled">
        </div>
        <div v-if="features.country" class="form-group">
          <label>{{ t('Country / Region') }}</label>
          <input type="text" :value="user.countryDisplay" disabled class="input-disabled">
        </div>
        <div class="form-group">
          <label for="display_name">{{ t('Display name') }}</label>
          <input id="display_name" v-model="profile.display_name" type="text" :placeholder="t('Preferred display name')">
        </div>
        <div class="form-group">
          <label for="bio">{{ t('Bio') }}</label>
          <textarea id="bio" v-model="profile.bio" rows="3" :placeholder="t('Introduce yourself')"></textarea>
        </div>
        <button type="submit" class="btn btn-primary" :disabled="profileSaving">{{ profileSaving ? t('Saving...') : t('Save changes') }}</button>
      </form>
    </div>

    <div class="settings-section" id="sec-password">
      <h2>{{ t('Change password') }}</h2>
      <form class="settings-form" @submit.prevent="savePassword">
        <div class="form-group">
          <label for="current_password">{{ t('Current password') }}</label>
          <input id="current_password" v-model="passwordForm.current" type="password" required>
        </div>
        <div class="form-group">
          <label for="new_password">{{ t('New password') }}</label>
          <div class="pw-wrap">
            <input id="new_password" v-model="passwordForm.next" type="password" required minlength="8" :placeholder="t('8+ characters, letters and digits')">
          </div>
          <div class="pw-strength">
            <div class="pw-strength__bar">
              <div class="pw-strength__fill" :style="{ width: passwordStrengthInfo.width, background: passwordStrengthInfo.color }"></div>
            </div>
            <span class="pw-strength__text" :style="{ color: passwordStrengthInfo.color }">{{ passwordStrengthText }}</span>
            <button type="button" class="pw-gen" :title="t('Generate a strong random password')" @mousedown.prevent @click="generatePassword">{{ t('Generate') }}</button>
          </div>
        </div>
        <div class="form-group">
          <label for="confirm_password">{{ t('Confirm new password') }}</label>
          <input id="confirm_password" v-model="passwordForm.confirm" type="password" required>
        </div>
        <button type="submit" class="btn btn-primary" :disabled="passwordSaving">{{ passwordSaving ? t('Saving...') : t('Change password') }}</button>
      </form>
    </div>

    <div class="settings-section" id="sec-reset-code">
      <h2>{{ t('Reset password via email code') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('Forgot the current password? Reset it with an email code') }}</p>
      <form class="settings-form" @submit.prevent="submitReset">
        <div v-if="verifiedEmails.length > 1" class="form-group">
          <label for="reset_code_target">{{ t('Code mailbox') }}</label>
          <select id="reset_code_target" v-model="resetForm.target" class="email-target-select">
            <option v-for="choice in verifiedEmails" :key="choice.email" :value="choice.email">{{ choice.email }}{{ choice.is_primary ? t('(primary)') : '' }}</option>
          </select>
        </div>
        <div class="form-group">
          <label>{{ t('Code') }}</label>
          <div class="input-with-button">
            <input id="reset_code" v-model="resetForm.code" type="text" name="reset_code" required :placeholder="t('Enter the 6-digit verification code')" maxlength="6">
            <button type="button" id="send-reset-code-btn" class="btn btn-secondary" :disabled="resetSending || resetCooldown > 0" @click="sendResetCode">
              {{ resetSending ? t('Sending...') : resetCooldown > 0 ? t('Retry in {:n}s', { n: resetCooldown }) : t('Send code') }}
            </button>
          </div>
        </div>
        <div class="form-group">
          <label for="reset_new_password">{{ t('New password') }}</label>
          <div class="pw-wrap">
            <input id="reset_new_password" v-model="resetForm.next" type="password" required minlength="8" :placeholder="t('8+ characters, letters and digits')">
          </div>
        </div>
        <div class="form-group">
          <label for="reset_confirm_password">{{ t('Confirm new password') }}</label>
          <input id="reset_confirm_password" v-model="resetForm.confirm" type="password" required>
        </div>
        <button type="submit" id="reset-btn" class="btn btn-primary" :disabled="resetSaving">{{ resetSaving ? t('Resetting...') : t('Reset password') }}</button>
      </form>
    </div>

    <div class="settings-section" id="sec-emails">
      <h2>{{ t('Email addresses') }}</h2>
      <p v-if="!user.email && features.guardians" class="form-hint" style="margin-bottom:16px;">
        {{ t('Your contact address is currently a parent\'s email. Once you link your own email, notifications go there; family matters (parents, ending supervision) are all on the ') }}<a href="/family">{{ t('Family') }}</a>{{ t(' page.') }}
      </p>
      <p class="form-hint" style="margin-bottom:16px;">
        {{ t('You can bind up to {:n} emails. Notifications and account recovery use the primary one; any verified address works for signing in.', { n: maxEmails }) }}
      </p>
      <EmailSetSection :csrf="csrf" :emails="emails" :max-emails="maxEmails" />
    </div>

    <div class="settings-section" id="sec-identities">
      <h2>{{ t('Third-party sign-in') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">
        {{ t('Linked accounts can sign in directly. Make sure you have another sign-in method before unlinking.') }}
      </p>

      <div v-if="linkedIdentities.length" class="identity-list">
        <div v-for="item in linkedIdentities" :key="item.provider_id" class="identity-row">
          <div class="identity-row__info">
            <strong>{{ item.provider_name }}</strong>
            <span class="text-muted">{{ item.display_name || item.email || '' }}</span>
          </div>
          <button type="button" class="btn btn-danger btn-sm" @click="unlinkIdentity(item)">{{ t('Unlink') }}</button>
        </div>
      </div>
      <p v-else class="form-hint">{{ t('No third-party accounts linked yet.') }}</p>

      <div v-if="unlinkedProviders.length" class="identity-links">
        <a
          v-for="provider in unlinkedProviders"
          :key="provider.slug"
          class="btn btn-secondary btn-sm"
          :href="`/auth/${provider.slug}/redirect?link=1`"
        >{{ t('Link {:name}', { name: provider.name }) }}</a>
      </div>
    </div>

    <div class="settings-section settings-section--danger" id="sec-account">
      <h2>{{ t('Account info') }}</h2>
      <div class="info-grid">
        <div class="info-item">
          <span class="info-label">{{ t('Registered at') }}</span>
          <span class="info-value">{{ user.createdAtDisplay }}</span>
        </div>
        <div class="info-item">
          <span class="info-label">{{ t('Contact address') }}</span>
          <span class="info-value">
            <span v-if="user.contactVerified" class="badge badge--success">{{ t('Verified') }}</span>
            <span v-else class="badge badge--warning">{{ t('Unverified') }}</span>
          </span>
        </div>
      </div>
    </div>

    <div class="settings-section" id="sec-active-devices">
      <h2>{{ t('Active devices') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('Devices active in the last 30 days — view only; revoke under All devices below') }}</p>
      <p v-if="!activeTokens.length" class="text-muted" style="padding:12px 0;">{{ t('No active devices') }}</p>
      <div v-else class="token-list">
        <div v-for="token in activeTokens" :key="token.deviceId" class="token-item">
          <div class="token-item__info">
            <div class="token-item__name">
              {{ token.deviceName }}
              <span v-if="token.isCurrent" class="badge badge--info" style="margin-left:8px;font-size:11px;">{{ t('Current device') }}</span>
            </div>
            <div class="token-item__meta">{{ token.meta }}</div>
          </div>
          <span class="badge badge--success">{{ t('Active') }}</span>
        </div>
      </div>
    </div>

    <div class="settings-section" id="sec-temp-keys">
      <h2>{{ t('Temp key') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('Temp keys let you sign in on untrusted devices (like a school computer) and skip device verification. Valid up to 14 days, 5 at a time.') }}</p>
      <TempKeysSection :csrf="csrf" :urls="urls.tempKeys" />
    </div>

    <div class="settings-section" id="sec-2fa">
      <h2>{{ t('Two-step verification') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('Choose when the second step is required. Default: only on untrusted new devices') }}</p>
      <form @submit.prevent="savePolicy">
        <div class="factor-select" style="margin-bottom:16px;">
          <label class="factor-option" :class="{ selected: policy === 'new_device' }">
            <input v-model="policy" type="radio" name="policy" value="new_device">
            <span class="factor-icon"><Icon name="monitor" /></span>
            <span class="factor-label">{{ t('New devices only (recommended)') }}</span>
          </label>
          <label class="factor-option" :class="{ selected: policy === 'every_login' }">
            <input v-model="policy" type="radio" name="policy" value="every_login">
            <span class="factor-icon"><Icon name="lock" /></span>
            <span class="factor-label">{{ t('Verify every sign-in') }}</span>
          </label>
        </div>
        <p class="form-hint" style="margin-bottom:12px;">{{ t('Before “verify every time”, verify an email or enable an authenticator app first') }}</p>
        <button type="submit" class="btn btn-primary" :disabled="policySaving">{{ policySaving ? t('Saving...') : t('Save policy') }}</button>
      </form>
    </div>

    <div class="settings-section" id="sec-locale" v-if="locales.length">
      <h2>{{ t('Interface language') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('Language for the {:sitename} interface and system emails. Signed-out devices follow the browser language') }}</p>
      <form method="POST" action="/settings/locale">
        <input type="hidden" name="_csrf" :value="csrf">
        <div class="form-group" style="margin-bottom:16px;">
          <label for="locale-select">{{ t('Language') }}</label>
          <select id="locale-select" name="locale">
            <option v-for="entry in locales" :key="entry.slug" :value="entry.slug" :selected="entry.slug === currentLocale">
              {{ entry.name }} ({{ entry.english }})
            </option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary">{{ t('Save') }}</button>
      </form>
    </div>

    <div class="settings-section" id="sec-skin">
      <h2>{{ t('Appearance') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('Choose how the site looks. Devices that are not signed in use the site default skin.') }}</p>
      <form method="POST" action="/settings/skin">
        <input type="hidden" name="_csrf" :value="csrf">
        <div class="form-group" style="margin-bottom:16px;">
          <label for="skin-select">{{ t('Skins') }}</label>
          <select id="skin-select" name="skin">
            <option v-for="entry in skins" :key="entry.slug" :value="entry.slug" :selected="entry.slug === currentSkin">
              {{ entry.name }}
            </option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary">{{ t('Save') }}</button>
      </form>
    </div>

    <div class="settings-section" id="sec-notifications">
      <h2>{{ t('Email notification preferences') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('Checked categories are also emailed to you. In-site messages (the bell, top right) always arrive regardless of these switches') }}</p>
      <form @submit.prevent="saveNotif">
        <label class="notif-pref-item">
          <input v-model="notif.system_important" type="checkbox" value="1">
          <span class="notif-pref-item__text">
            <strong>{{ t('Important system notice') }}</strong>
            <span class="form-hint">{{ t('Security events that need immediate attention (keep this on)') }}</span>
          </span>
        </label>
        <label class="notif-pref-item">
          <input v-model="notif.system_general" type="checkbox" value="1">
          <span class="notif-pref-item__text">
            <strong>{{ t('General system notice') }}</strong>
            <span class="form-hint">{{ t('General notices: security advice, feature updates') }}</span>
          </span>
        </label>
        <label class="notif-pref-item">
          <input v-model="notif.system_interact" type="checkbox" value="1">
          <span class="notif-pref-item__text">
            <strong>{{ t('System interaction') }}</strong>
            <span class="form-hint">{{ t('Interactions related to your account') }}</span>
          </span>
        </label>
        <button type="submit" class="btn btn-primary" style="margin-top:12px;" :disabled="notifSaving">{{ notifSaving ? t('Saving...') : t('Save') }}</button>
      </form>
    </div>

    <div class="settings-section" id="sec-totp">
      <h2>{{ t('Authenticator app (TOTP)') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('Generate 6-digit codes with a TOTP app like Google Authenticator or Authy as the second factor') }}</p>
      <TotpSection :csrf="csrf" />
    </div>

    <div class="settings-section" id="sec-recovery">
      <h2>{{ t('Recovery codes') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('Recovery codes are the last-resort method: when the authenticator app or email codes aren\'t available, they complete sign-in verification. Each code works once. They work on their own — even without enabling TOTP, you can pick “recovery code” during two-step verification. Print or write them down right away and keep them safe.') }}</p>
      <RecoveryCodesSection :csrf="csrf" />
    </div>

    <div class="settings-section" id="sec-biometric">
      <h2>{{ t('Biometric') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('Binding biometrics (like Windows Hello, fingerprint or Face ID) enables quick sign-in. It\'s one of your {:label} and doesn\'t count toward sign-in methods. Up to 5 devices at a time', { label: user.assistLabel }) }}</p>
      <BiometricsSection :csrf="csrf" />
    </div>

    <div class="settings-section" id="sec-devices">
      <h2>{{ t('All devices') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('Trusted devices skip extra verification. After revoking, the device must verify again on next sign-in') }}</p>
      <p v-if="!devices.length" class="text-muted" style="padding:12px 0;">{{ t('No trusted devices') }}</p>
      <div v-else class="device-list" id="device-list">
        <div v-for="device in devices" :key="device.deviceId" class="device-item">
          <div class="device-item__info">
            <div class="device-item__name">
              {{ device.deviceName }}
              <span v-if="device.isCurrent" class="badge badge--info" style="margin-left:8px;font-size:11px;">{{ t('Current device') }}</span>
            </div>
            <div class="device-item__meta">{{ device.meta }}</div>
          </div>
          <button v-if="!device.isCurrent" type="button" class="btn btn-sm btn-danger-outline" @click="revokeDevice(device)">{{ t('Revoke') }}</button>
        </div>
      </div>
    </div>

    <div v-if="features.guardians" class="settings-section" id="sec-family">
      <h2>{{ t('Family') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">
        {{ t('Once a parent joins your family, they can reset your password or temporarily lock the account. You can also invite a parent here, or (once you reach the age for your region) end supervision and leave the family.') }}
      </p>
      <a href="/family" class="btn btn-secondary">{{ t('Open Family') }}</a>
    </div>
  </div>
</template>
