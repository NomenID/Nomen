<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed, inject, onBeforeUnmount, reactive, ref } from 'vue'
import Icon from '../../components/Icon.vue'
import { apiPost } from '../../app/api'
import { toast } from '../../lib/toast.js'
import { generateStrongPassword, passwordStrength, copyText } from '../../composables/usePasswordTools.js'
import { url } from '../../lib/url.js'
import { useT } from '../../composables/useT.js'
import BiometricsSection from './BiometricsSection.vue'
import EmailSetSection from './EmailSetSection.vue'
import RecoveryCodesSection from './RecoveryCodesSection.vue'
import TotpSection from './TotpSection.vue'

const { t } = useT()
const data = inject('settingsData')
const csrf = inject('csrf')

const user = computed(() => data.value.user)
const features = computed(() => data.value.features || {})
const emails = computed(() => data.value.emails || [])
const verifiedEmails = computed(() => data.value.verifiedEmails || [])
const maxEmails = computed(() => data.value.maxEmails || 5)

// ---- 密码表单 ----
const passwordForm = reactive({ current: '', next: '', confirm: '' })
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
    const result = await apiPost('/settings/password', {
      current_password: passwordForm.current,
      new_password: passwordForm.next,
      confirm_password: passwordForm.confirm,
    })
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
const resetForm = reactive({
  target: verifiedEmails.value.find((choice) => choice.is_primary)?.email || verifiedEmails.value[0]?.email || '',
  code: '',
  next: '',
  confirm: '',
})
const resetSending = ref(false)
const resetSaving = ref(false)
const resetCooldown = ref(0)
let resetTimer = null

async function sendResetCode() {
  if (resetSending.value || resetCooldown.value > 0) return
  resetSending.value = true

  const params = {}
  if (verifiedEmails.value.length > 1) params.send_to = resetForm.target

  try {
    const result = await apiPost('/settings/password/send-code', params)
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

onBeforeUnmount(() => {
  if (resetTimer) clearInterval(resetTimer)
})

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
  try {
    const result = await apiPost('/settings/password/reset-code', {
      reset_code: code,
      new_password: resetForm.next,
      confirm_password: resetForm.confirm,
    })
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

// ---- 两步验证策略 ----
const policy = ref(user.value.twoFactorPolicy || 'new_device')
const policySaving = ref(false)

async function savePolicy() {
  if (policySaving.value) return
  policySaving.value = true
  try {
    const result = await apiPost('/settings/two-factor/policy', { policy: policy.value })
    toast(result.message || (result.success ? t('Saved') : t('Save failed')), result.success ? 'success' : 'error')
  } catch (err) {
    toast(t('Network error'), 'error')
  } finally {
    policySaving.value = false
  }
}
</script>

<template>
  <div class="settings-section" id="sec-emails">
    <h2>{{ t('Email addresses') }}</h2>
    <p v-if="!user.email && features.guardians" class="form-hint" style="margin-bottom:16px;">
      {{ t('Your contact address is currently a parent\'s email. Once you link your own email, notifications go there; family matters (parents, ending supervision) are all on the ') }}<a :href="url('/family')">{{ t('Family') }}</a>{{ t(' page.') }}
    </p>
    <p class="form-hint" style="margin-bottom:16px;">
      {{ t('You can bind up to {:n} emails. Notifications and account recovery use the primary one; any verified address works for signing in.', { n: maxEmails }) }}
    </p>
    <EmailSetSection :csrf="csrf" :emails="emails" :max-emails="maxEmails" />
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
</template>
