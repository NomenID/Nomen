<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useT } from '../../composables/useT.js'
const { t } = useT()
import BaseAlert from '../../components/BaseAlert.vue'
import { url } from '../../lib/url.js'

const props = defineProps({
  csrf: { type: String, default: '' },
  error: { type: String, default: '' },
  success: { type: String, default: '' },
  email: { type: String, default: '' },
  usesGuardianAddress: { type: Boolean, default: false },
  brandLogo: { type: String, default: '/assets/brand/nomen-icon.svg' },
})

const sending = ref(false)
const cooldown = ref(0)
let timer = null

const alert = reactive({
  type: props.error ? 'error' : (props.success ? 'success' : ''),
  message: props.error || props.success || '',
})
const alertKey = ref(0)

const countdownText = computed(() => {
  const m = Math.floor(cooldown.value / 60)
  const s = cooldown.value % 60
  return (m > 0 ? m + t('min') : '') + s + t('s to retry')
})

const deleteForm = ref(null)

function showAlert(type, message) {
  alert.type = type
  alert.message = message
  alertKey.value += 1
}

function startCooldown(seconds) {
  cooldown.value = Math.max(0, Number(seconds) || 0)
  if (timer) clearInterval(timer)
  timer = setInterval(() => {
    cooldown.value -= 1
    if (cooldown.value <= 0) {
      clearInterval(timer)
      timer = null
      cooldown.value = 0
    }
  }, 1000)
}

async function sendVerification() {
  if (sending.value || cooldown.value > 0) return
  sending.value = true

  const body = new URLSearchParams()
  body.set('_csrf', props.csrf)

  try {
    const response = await fetch(url('/activate/send'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body.toString(),
    })
    const data = await response.json()
    showAlert(data.success ? 'success' : 'error', data.message || t('Request failed'))
    if (data.success) {
      startCooldown(60)
    } else if (data.retry_after) {
      startCooldown(data.retry_after)
    }
  } catch (err) {
    showAlert('error', t('Network error'))
  } finally {
    sending.value = false
  }
}

async function confirmDelete() {
  if (!window.Nomen || typeof window.Nomen.confirm !== 'function') return

  const confirmed = await window.Nomen.confirm(t('Delete account'), t('Really delete this account? This can\'t be undone and your data is gone for good.'), {
    confirmText: t('Confirm account deletion'),
    cancelText: t('Let me think'),
    danger: true,
    icon: 'trash',
  })
  if (!confirmed) return

  const confirmedAgain = await window.Nomen.confirm(t('Final confirmation'), t('Really delete? This can\'t be undone!'), {
    confirmText: t('Confirm delete'),
    cancelText: t('Cancel'),
    danger: true,
    icon: 'warning',
  })
  if (!confirmedAgain) return

  const form = deleteForm.value
  if (!form) return
  window.Nomen.beginSubmit(form)
  form.submit()
}

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
      <h1>{{ t('Email verification') }}</h1>
      <p>{{ usesGuardianAddress ? t('Ask a parent to confirm this email') : t('Verify your email address to activate your account') }}</p>
    </div>

    <div id="alert-container">
      <BaseAlert v-if="alert.message" :key="alertKey" :type="alert.type" :message="alert.message" />
    </div>

    <div class="activate-info">
      <p>{{ usesGuardianAddress ? t('The confirmation email goes to the parent\'s address: ') : t('The verification email goes to: ') }}</p>
      <p class="email-display">{{ email }}</p>
    </div>

    <form id="activate-form" class="auth-form" @submit.prevent="sendVerification">
      <input type="hidden" name="_csrf" :value="csrf">
      <button type="submit" id="send-btn" class="btn btn-primary btn-block" :disabled="sending || cooldown > 0">
        <span v-show="cooldown <= 0" id="btn-text">{{ sending ? t('Sending...') : t('Send verification email') }}</span>
        <span v-show="cooldown > 0" id="btn-countdown">{{ countdownText }}</span>
      </button>
    </form>

    <div class="activate-tips">
      <p>{{ t('Tips:') }}</p>
      <ul>
        <template v-if="usesGuardianAddress">
          <li>{{ t('The account can\'t be used until a parent confirms') }}</li>
          <li>{{ t('Ask the parent to check their inbox and spam folder') }}</li>
        </template>
        <li v-else>{{ t('Check your inbox and spam folder') }}</li>
        <li>{{ t('The link is valid for 24 hours') }}</li>
        <li>{{ t('Up to 5 verification emails per day') }}</li>
      </ul>
    </div>

    <div class="activate-warning">
      <p>{{ t('If an account is not activated within ') }} <strong>{{ t('7 days') }}</strong>{{ t('If the account is not activated, the site may release your account and email address.') }}</p>
    </div>

    <div class="auth-footer">
      <p>{{ t('Already verified?') }} <a href="/login">{{ t('Sign in again') }}</a></p>
    </div>

    <div class="delete-account-section">
      <button type="button" class="btn-link-danger" @click="confirmDelete">{{ t('Don\'t want this account anymore?') }}</button>
      <form ref="deleteForm" method="POST" action="/activate/delete-account" style="display:none;">
        <input type="hidden" name="_csrf" :value="csrf">
      </form>
    </div>
  </div>
</template>
