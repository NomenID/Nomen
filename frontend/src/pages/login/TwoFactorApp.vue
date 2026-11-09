<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { useT } from '../../composables/useT.js'
const { t } = useT()
import BaseAlert from '../../components/BaseAlert.vue'
import Icon from '../../components/Icon.vue'
import { toast } from '../../lib/toast.js'

const props = defineProps({
  csrf: { type: String, default: '' },
  nonce: { type: String, default: '' },
  factors: { type: Object, default: () => ({}) },
  firstLabel: { type: String, default: 'First step verification' },
  error: { type: String, default: '' },
  emailChoices: { type: Array, default: () => [] },
  brandLogo: { type: String, default: '/assets/brand/nomen-icon.svg' },
  urls: { type: Object, default: () => ({}) },
})

const urls = {
  sendCode: props.urls?.sendCode || '/2fa/verify/send-code',
  verify: props.urls?.verify || '/2fa/verify',
}

const selected = ref(null)
const values = reactive({})
const sending = ref(false)
const verifying = ref(false)
const countdown = ref(0)
let countdownTimer = null

const primaryEmail = props.emailChoices.find((choice) => choice.is_primary) || props.emailChoices[0]
const emailTarget = ref(primaryEmail?.email || '')

const verifyLabel = computed(() => {
  if (!selected.value) return t('Verify and sign in')
  return {
    password: t('Verify password'),
    email_code: t('Verify email'),
    totp: t('Verify dynamic code'),
    recovery_code: t('Verify recovery code'),
  }[selected.value] || t('Verify')
})

const sendText = computed(() => {
  if (sending.value) return t('Sending...')
  if (countdown.value > 0) return t('Resend ({:n}s)', { n: countdown.value })
  return t('Send code')
})

function startCountdown() {
  countdown.value = 60
  if (countdownTimer) clearInterval(countdownTimer)
  countdownTimer = setInterval(() => {
    countdown.value -= 1
    if (countdown.value < 0) {
      clearInterval(countdownTimer)
      countdownTimer = null
      countdown.value = 0
    }
  }, 1000)
}

async function sendCode() {
  if (sending.value || countdown.value > 0) return
  sending.value = true

  const body = new URLSearchParams()
  body.set('_csrf', props.csrf)
  body.set('nonce', props.nonce)
  if (props.emailChoices.length > 1) body.set('send_to', emailTarget.value)

  try {
    const response = await fetch(urls.sendCode, {
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
      startCountdown()
      return
    }
    toast(data.message, 'error')
  } catch (err) {
    toast(t('Network error'), 'error')
  } finally {
    sending.value = false
  }
}

async function verify() {
  if (!selected.value) {
    toast(t('Pick a verification method'), 'warning')
    return
  }

  const value = String(values[selected.value] || '').trim()
  if (!value) {
    toast(t('Enter the verification value'), 'warning')
    return
  }

  if (verifying.value) return
  verifying.value = true

  const body = new URLSearchParams()
  body.set('_csrf', props.csrf)
  body.set('nonce', props.nonce)
  body.set('factor_type', selected.value)
  body.set('factor_value', value)

  try {
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
      toast(t('Verification passed'), 'success')
      window.location.href = data.redirect || '/dashboard'
      return
    }
    toast(data.message, 'error')
  } catch (err) {
    toast(t('Network error'), 'error')
  } finally {
    verifying.value = false
  }
}

onBeforeUnmount(() => {
  if (countdownTimer) clearInterval(countdownTimer)
})
</script>

<template>
  <div class="auth-card auth-card--2fa">
    <div class="auth-header">
      <div class="auth-logo">
        <img :src="brandLogo" alt="" width="48" height="48">
      </div>
      <h1>{{ t('Two-step verification') }}</h1>
      <p>{{ t(firstLabel) }}{{ t(' passed. Now choose another method to confirm it\'s you') }}</p>
    </div>

    <BaseAlert v-if="error" type="error" :message="error" />

    <form id="twofa-form" class="auth-form" @submit.prevent>
      <input type="hidden" name="_csrf" :value="csrf">
      <input type="hidden" name="nonce" :value="nonce">

      <div class="form-group">
        <label>{{ t('Choose a verification method') }}</label>
        <div class="factor-select">
          <label
            v-for="(factor, type) in factors"
            :key="type"
            class="factor-option"
            :class="{ selected: selected === type }"
          >
            <input v-model="selected" type="radio" name="factor_type" :value="type">
            <span class="factor-icon"><Icon :name="factor.icon || 'lock'" /></span>
            <span class="factor-label">{{ t(factor.label) }}</span>
          </label>
        </div>
      </div>

      <div
        v-for="(factor, type) in factors"
        :key="'input-' + type"
        v-show="selected === type"
        class="form-group factor-input"
        :id="'factor-input-' + type"
      >
        <label :for="'factor-value-' + type">{{ t(factor.label) }}</label>

        <div v-if="type === 'email_code'" class="input-with-button">
          <input
            :id="'factor-value-' + type"
            v-model="values[type]"
            :type="factor.input_type"
            :placeholder="t(factor.placeholder)"
            maxlength="6"
            inputmode="numeric"
            autocomplete="one-time-code"
          >
          <button
            type="button"
            id="send-2fa-code-btn"
            class="btn btn-secondary"
            :disabled="sending || countdown > 0"
            @click="sendCode"
          >{{ sendText }}</button>
        </div>

        <input
          v-else
          :id="'factor-value-' + type"
          v-model="values[type]"
          :type="factor.input_type"
          :placeholder="t(factor.placeholder)"
          autocomplete="off"
        >

        <template v-if="type === 'email_code' && emailChoices.length > 1">
          <select id="twofa-email-target" v-model="emailTarget" class="email-target-select">
            <option v-for="choice in emailChoices" :key="choice.email" :value="choice.email">{{ choice.email }}{{ choice.is_primary ? t('(primary)') : '' }}</option>
          </select>
          <p class="form-hint">{{ t('The code goes to the address above — switch it in the dropdown') }}</p>
        </template>
      </div>

      <button
        type="button"
        id="verify-2fa-btn"
        class="btn btn-primary btn-block"
        :disabled="!selected || verifying"
        @click="verify"
      >{{ verifyLabel }}</button>

      <div class="auth-footer">
        <p><a href="/logout">{{ t('Cancel and go back to sign-in') }}</a></p>
        <p><a href="/recovery">{{ t('I can\'t sign in. Contact support.') }}</a></p>
      </div>
    </form>
  </div>
</template>
