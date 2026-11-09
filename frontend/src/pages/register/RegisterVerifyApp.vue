<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useT } from '../../composables/useT.js'
const { t } = useT()
import BaseAlert from '../../components/BaseAlert.vue'

const props = defineProps({
  csrf: { type: String, default: '' },
  email: { type: String, default: '' },
  maskedEmail: { type: String, default: '' },
  isMinor: { type: Boolean, default: false },
  error: { type: String, default: '' },
  success: { type: String, default: '' },
  resendAfter: { type: Number, default: 0 },
  brandLogo: { type: String, default: '/assets/brand/nomen-icon.svg' },
})

const left = ref(Math.max(0, Number(props.resendAfter) || 0))
let timer = null

function render() {
  if (left.value <= 0) {
    if (timer) {
      clearInterval(timer)
      timer = null
    }
    left.value = 0
  }
}

onMounted(() => {
  if (left.value > 0) {
    timer = setInterval(() => {
      left.value -= 1
      render()
    }, 1000)
  }
  document.getElementById('code')?.focus()
})

onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
})
</script>

<template>
  <div class="auth-card">
    <div class="auth-header">
      <div class="auth-logo">
        <img :src="brandLogo" alt="" width="48" height="48">
      </div>
      <h1>{{ t('This email is already taken') }}</h1>
      <p>{{ t('Verify your email to finish signing up') }}</p>
    </div>

    <div id="alert-container">
      <BaseAlert v-if="error" type="error" :message="error" />
      <BaseAlert v-if="success" type="success" :message="success" />
    </div>

    <div class="claim-note">
      <p>{{ t('Hi, welcome to {:sitename}.') }}</p>
      <p>{{ t('This email was registered before (by a person or a bot), but that account never verified the address, so it was never properly activated.') }}</p>
      <p>{{ t('Verify your email first: enter the 6-digit code we just sent you. Once verified, we\'ll remove the unverified account and register and activate this one with the details you just entered.') }}</p>
    </div>

    <div class="activate-info">
      <p>{{ isMinor ? t('The code was sent to the parent\'s address: ') : t('The code was sent to: ') }}</p>
      <p class="email-display">{{ maskedEmail }}</p>
    </div>

    <form id="claim-form" method="POST" action="/register/verify" class="auth-form">
      <input type="hidden" name="_csrf" :value="csrf">
      <input type="hidden" name="email" :value="email">

      <div class="form-group">
        <label for="code">{{ t('6-digit code') }}</label>
        <input
          id="code"
          type="text"
          name="code"
          required
          autofocus
          inputmode="numeric"
          autocomplete="one-time-code"
          maxlength="6"
          pattern="[0-9]{6}"
          placeholder="000000"
          class="claim-code-input"
        >
      </div>

      <button type="submit" class="btn btn-primary btn-block">{{ t('Verify and finish') }}</button>
    </form>

    <form id="resend-form" method="POST" action="/register/verify/resend" class="claim-resend-form">
      <input type="hidden" name="_csrf" :value="csrf">
      <input type="hidden" name="email" :value="email">
      <button type="submit" id="resend-btn" class="btn btn-secondary btn-block" :disabled="left > 0">
        <span id="resend-text">{{ left > 0 ? t('Resend (in {:n}s)', { n: left }) : t('Resend code') }}</span>
      </button>
    </form>

    <div class="activate-tips">
      <p>{{ t('Tips:') }}</p>
      <ul>
        <li>{{ t('Check your inbox and spam folder') }}</li>
        <li>{{ t('The code is valid for 10 minutes') }}</li>
        <li v-if="isMinor">{{ t('The code went to the parent\'s email — remind them to check') }}</li>
      </ul>
    </div>

    <div class="auth-footer">
      <p>{{ t('Want a different email?') }} <a href="/register">{{ t('Back to sign-up') }}</a></p>
    </div>
  </div>
</template>
