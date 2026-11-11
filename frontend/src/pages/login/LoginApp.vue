<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useT } from '../../composables/useT.js'
const { t } = useT()
import BaseAlert from '../../components/BaseAlert.vue'
import CaptchaInput from '../../components/CaptchaInput.vue'
import { consumeHandoff, pickLoginMethod, recommendMethod, rememberedAccount } from '../../composables/useLoginMemory.js'
import { parseJsonResponse } from '../../lib/http.js'
import { base64urlToBytes, bytesToBase64url } from '../../lib/webauthn.js'
import { toast } from '../../lib/toast.js'

const props = defineProps({
  csrf: { type: String, default: '' },
  error: { type: String, default: '' },
  success: { type: String, default: '' },
  redirectTo: { type: String, default: '/dashboard' },
  requireCaptcha: { type: Boolean, default: false },
  failedMethods: { type: Array, default: () => [] },
  triedLabels: { type: Object, default: () => ({}) },
  initialUsername: { type: String, default: '' },
  initialEmail: { type: String, default: '' },
  legalLinks: { type: Array, default: () => [] },
  supportEmail: { type: String, default: '' },
  supportName: { type: String, default: '' },
  mailEnabled: { type: Boolean, default: true },
  brandLogo: { type: String, default: '/assets/brand/nomen-icon.svg' },
  providers: { type: Array, default: () => [] },
  methods: { type: Object, default: () => ({ password: true, email_code: true, biometric: true, temp_key: true, oauth: true }) },
  urls: { type: Object, default: () => ({}) },
})

const urls = {
  sendCode: props.urls?.sendCode || '/login/send-code',
  biometricOptions: props.urls?.biometricOptions || '/biometric/auth/options',
  biometricVerify: props.urls?.biometricVerify || '/biometric/auth/verify',
}

consumeHandoff()
const remembered = rememberedAccount()

const biometricSupported = ref(!!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.get))

function available(method) {
  if (method === 'password') return props.methods.password
  if (method === 'email_code') return props.methods.email_code && props.mailEnabled
  if (method === 'biometric') return props.methods.biometric && biometricSupported.value
  return true
}

function providerUrl(slug) {
  const base = `/auth/${slug}/redirect?redirect_to=${encodeURIComponent(props.redirectTo)}`
  return form.temporary ? base + '&temporary=1' : base
}

const localMethods = ['password', 'email_code', 'biometric']
const localAvailable = computed(() => localMethods.filter(available))
const moreAvailable = computed(() =>
  localAvailable.value.some((method) => method !== current.value) || props.methods.temp_key
)

const tried = reactive({})
for (const method of props.failedMethods || []) tried[method] = true

const current = ref(pickLoginMethod(
  tried,
  Object.keys(tried).length ? null : recommendMethod(available),
  available,
))
const moreOpen = ref(false)
const switchHint = ref('')

const form = reactive({
  username: props.initialUsername || remembered?.u || '',
  password: '',
  email: props.initialEmail || remembered?.e || '',
  code: '',
  temporary: false,
  captchaPassword: '',
  captchaCode: '',
})

const accountId = ref('')
const sending = ref(false)
const countdown = ref(0)
let countdownTimer = null

const biometricIdentifier = ref(remembered?.u || '')
const biometricBusy = ref(false)
const biometricBtnText = ref(t('Use this device\'s biometrics'))
const biometricStatus = ref('')
const biometricManual = ref(false)
const biometricIdentifierEl = ref(null)

const pick = reactive({
  visible: false,
  mode: 'account',
  items: [],
  preferred: null,
  listOpen: false,
})

const triedMethods = computed(() => Object.keys(tried).filter((method) => method !== current.value))

const sendCodeText = computed(() => {
  if (sending.value) return t('Sending...')
  if (countdown.value > 0) return t('Retry in {:n}s', { n: countdown.value })
  return t('Send code')
})

function labelFor(method) {
  return props.triedLabels[method] || method
}

function announceSwitch(method) {
  const allTried = ['password', 'email_code', 'biometric'].every((m) => tried[m] || !available(m))
  switchHint.value = allTried
    ? t('None of these methods worked this time. If you\'re still locked out, start account recovery with the instructions at the bottom of the page.')
    : t('Switched to “{:method}”. You can change it under More sign-in options below.', { method: labelFor(method) })
}

function showMethod(method) {
  if (!available(method)) method = localAvailable.value[0] ?? null
  current.value = method
  moreOpen.value = false
  return method
}

function resetPick() {
  pick.visible = false
  pick.items = []
  pick.preferred = null
  pick.listOpen = false
  accountId.value = ''
}

function preferredAccount(accounts) {
  const name = remembered?.u ? String(remembered.u) : ''
  return accounts.find((account) => name && account.username === name) || null
}

function renderPick(mode, items, preferred) {
  if (!items || !items.length) return
  pick.mode = mode
  pick.items = items
  pick.preferred = preferred
  pick.visible = true
  pick.listOpen = mode === 'address' || !preferred
}

function onEmailInput() {
  resetPick()
}

function pickName(mode, item) {
  if (!item) return ''
  return mode === 'address'
    ? (item.masked || '')
    : `${item.display_name || item.username}（${item.username}）`
}

function tagFor(mode, item) {
  if (!item) return ''
  if (mode === 'address') return item.is_primary ? t('Primary') : ''
  return item.as_primary ? t('Primary') : t('Backup')
}

function togglePickList() {
  pick.listOpen = !pick.listOpen
}

function startCountdown() {
  countdown.value = 60
  if (countdownTimer) clearInterval(countdownTimer)
  countdownTimer = setInterval(() => {
    countdown.value -= 1
    if (countdown.value <= 0) {
      clearInterval(countdownTimer)
      countdownTimer = null
      countdown.value = 0
    }
  }, 1000)
}

async function sendCode(extra = {}) {
  const identifier = form.email.trim()
  if (!identifier) {
    toast(t('Enter an email or username'), 'warning')
    return
  }

  const identifierOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(identifier) || /^[a-z0-9_]{3,32}$/.test(identifier)
  if (!identifierOk) {
    toast(t('Enter a valid email address or username'), 'warning')
    return
  }

  if (sending.value || countdown.value > 0) return

  sending.value = true
  const data = new FormData()
  data.append('email', identifier)
  data.append('_csrf', props.csrf)
  if (extra.account_id) data.append('account_id', extra.account_id)
  if (extra.send_to_id) data.append('send_to_id', extra.send_to_id)
  if (props.requireCaptcha) data.append('captcha', form.captchaCode.trim())

  try {
    const response = await fetch(urls.sendCode, {
      method: 'POST',
      body: data,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    const result = await response.json()

    if (result.success) {
      accountId.value = extra.account_id || ''
      pick.visible = false
      toast(result.message, 'success')
      startCountdown()
      return
    }

    if (result.need_account_choice) {
      renderPick('account', result.accounts || [], preferredAccount(result.accounts || []))
      toast(result.message, 'info')
    } else if (result.need_address_choice) {
      renderPick('address', result.addresses || [], null)
      toast(result.message, 'info')
    } else {
      toast(result.message, 'error')
    }
  } catch (err) {
    toast(t('Send failed, try again later'), 'error')
  } finally {
    sending.value = false
  }
}

function onSendClick() {
  sendCode(accountId.value ? { account_id: accountId.value } : {})
}

function onPick(item) {
  if (sending.value || countdown.value > 0) return
  sendCode(pick.mode === 'address' ? { send_to_id: item.id } : { account_id: item.id })
}

function revealManual() {
  biometricManual.value = true
  nextTick(() => biometricIdentifierEl.value?.focus())
}

function resetBiometric() {
  biometricBusy.value = false
  biometricBtnText.value = t('Use this device\'s biometrics')
  biometricStatus.value = ''
}

function onBiometricFail() {
  tried.biometric = true
  announceSwitch(showMethod(pickLoginMethod(tried, null, available)))
}

async function biometricLogin() {
  if (!biometricSupported.value) {
    toast(t('This browser doesn\'t support biometric sign-in'), 'warning')
    return
  }
  if (biometricBusy.value) return

  const identifier = biometricIdentifier.value.trim()
  const optionsUrl = urls.biometricOptions + (identifier ? '?identifier=' + encodeURIComponent(identifier) : '')

  biometricBusy.value = true
  biometricBtnText.value = t('Preparing...')
  biometricStatus.value = ''

  try {
    const optionsResponse = await fetch(optionsUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    const data = await parseJsonResponse(optionsResponse)
    if (!data.success) {
      const error = new Error(data.message || t('Couldn\'t start biometric verification'))
      error.cldMessage = data.message || ''
      throw error
    }

    const options = data.options
    options.publicKey.challenge = base64urlToBytes(options.publicKey.challenge)
    if (options.publicKey.allowCredentials) {
      options.publicKey.allowCredentials.forEach((credential) => {
        credential.id = base64urlToBytes(credential.id)
      })
    }

    biometricBtnText.value = t('Complete the biometric verification...')
    biometricStatus.value = t('Calling system biometrics (fingerprint / face / PIN)...')

    const credential = await navigator.credentials.get({ publicKey: options.publicKey })
    if (!credential) throw new Error('cancelled')

    const response = credential.response
    const verifyResponse = await fetch(urls.biometricVerify, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        temporary: form.temporary,
        credential: {
          id: credential.id,
          type: credential.type,
          rawId: bytesToBase64url(credential.rawId),
          authenticatorData: bytesToBase64url(response.authenticatorData),
          clientDataJSON: bytesToBase64url(response.clientDataJSON),
          signature: bytesToBase64url(response.signature),
          userHandle: response.userHandle ? bytesToBase64url(response.userHandle) : null,
        },
      }),
    })
    const result = await parseJsonResponse(verifyResponse)

    if (result.success) {
      toast(result.message, 'success')
      setTimeout(() => { window.location.href = result.redirect || '/dashboard' }, 800)
      return
    }

    toast(result.message, 'error')
    if (result.redirect) {
      setTimeout(() => { window.location.href = result.redirect }, 1000)
      return
    }

    resetBiometric()
    onBiometricFail()
  } catch (error) {
    resetBiometric()

    if (error && error.message === 'cancelled') return

    if (error && error.name === 'NotAllowedError') {
      biometricStatus.value = t('Verification didn\'t complete. If this device has no passkey for the account, enter the username or email below and retry.')
      revealManual()
      return
    }

    toast(error && error.message ? error.message : t('Biometric sign-in failed, try again'), 'error')

    if (error && error.cldMessage) {
      biometricStatus.value = error.cldMessage
      revealManual()
      onBiometricFail()
    }
  }
}

onMounted(() => {
  if (current.value === 'password') document.getElementById('username')?.focus()
  if (Object.keys(tried).length) announceSwitch(current.value)
})

onBeforeUnmount(() => {
  if (countdownTimer) clearInterval(countdownTimer)
})
</script>

<template>
  <div class="auth-card">
    <div class="auth-header">
      <div class="auth-logo">
        <img :src="brandLogo" alt="" width="48" height="48">
      </div>
      <h1>{{ t('Sign in to {:sitename}') }}</h1>
      <p>{{ t('Continue with your {:sitename} account') }}</p>
    </div>

    <BaseAlert v-if="error" type="error" :message="error" />
    <BaseAlert v-if="success" type="success" :message="success" />

    <p v-show="switchHint" class="login-switch-hint" id="login-switch-hint">{{ switchHint }}</p>

    <div
      v-show="current !== null || (props.methods.oauth && props.providers.length)"
      class="form-group form-group--inline login-device"
    >
      <label class="checkbox-label">
        <input v-model="form.temporary" type="checkbox" id="temporary-login">
        <span class="checkmark"></span>
        <span>{{ t('This is a public device') }}</span>
      </label>
    </div>

    <div class="login-primary" v-show="current !== null">
      <form method="POST" action="/login" class="auth-form" id="password-form" v-show="current === 'password'">
        <input type="hidden" name="_csrf" :value="csrf">
        <input type="hidden" name="redirect_to" :value="redirectTo">
        <input type="hidden" name="temporary" :value="form.temporary ? '1' : ''">

        <div class="form-group">
          <label for="username">{{ t('Username or email') }}</label>
          <input
            id="username"
            v-model="form.username"
            type="text"
            name="username"
            required
            :placeholder="t('Enter a username or email address')"
          >
        </div>

        <div class="form-group">
          <label for="password">{{ t('Password') }}</label>
          <input
            id="password"
            v-model="form.password"
            type="password"
            name="password"
            required
            autocomplete="current-password"
            :placeholder="t('Enter the password')"
          >
        </div>

        <div v-if="requireCaptcha" class="form-group">
          <label>{{ t('CAPTCHA') }}</label>
          <CaptchaInput
            v-model="form.captchaPassword"
            input-id="login-captcha"
            img-id="login-captcha-img"
          />
          <p class="form-hint">{{ t('Too many sign-in attempts — enter the CAPTCHA') }}</p>
        </div>

        <button type="submit" class="btn btn-primary btn-block">{{ t('Sign in') }}</button>
      </form>

      <form method="POST" action="/login/code" class="auth-form" id="code-form" v-show="current === 'email_code'">
        <input type="hidden" name="_csrf" :value="csrf">
        <input type="hidden" name="redirect_to" :value="redirectTo">
        <input type="hidden" name="temporary" :value="form.temporary ? '1' : ''">

        <div class="form-group">
          <label for="email">{{ t('Email or username') }}</label>
          <input
            id="email"
            v-model="form.email"
            type="text"
            name="email"
            required
            autocomplete="username"
            :placeholder="t('Sign-up email, or your username')"
            @input="onEmailInput"
          >
          <p class="form-hint">{{ t('The code goes to whichever email you enter (any address bound to the account works). With a username you pick the mailbox; for child accounts it goes to the parent\'s email') }}</p>
        </div>

        <div v-show="pick.visible" class="login-account-pick" id="login-account-pick">
          <p class="login-account-pick__tip" id="login-account-pick-tip">
            {{ pick.mode === 'address' ? t('Several addresses are bound — pick one for the code') : t('This address matches several accounts — pick one to sign in') }}
          </p>
          <div id="login-account-pick-primary">
            <button
              v-if="pick.mode === 'account' && pick.preferred"
              type="button"
              class="login-account-pick__item"
              @click="onPick(pick.preferred)"
            >
              <img v-if="pick.preferred.avatar" class="login-account-pick__avatar" :src="pick.preferred.avatar" alt="">
              <span class="login-account-pick__name">{{ pickName(pick.mode, pick.preferred) }}</span>
              <span v-if="tagFor(pick.mode, pick.preferred)" class="login-account-pick__tag">{{ tagFor(pick.mode, pick.preferred) }}</span>
            </button>
          </div>
          <div v-show="pick.listOpen" class="login-account-pick__list" id="login-account-pick-list">
            <button
              v-for="item in pick.items"
              :key="item.id"
              type="button"
              class="login-account-pick__item"
              @click="onPick(item)"
            >
              <img v-if="item.avatar" class="login-account-pick__avatar" :src="item.avatar" alt="">
              <span class="login-account-pick__name">{{ pickName(pick.mode, item) }}</span>
              <span v-if="tagFor(pick.mode, item)" class="login-account-pick__tag">{{ tagFor(pick.mode, item) }}</span>
            </button>
          </div>
          <button
            v-if="pick.mode === 'account' && pick.preferred"
            type="button"
            class="login-link-btn"
            id="login-account-pick-switch"
            @click="togglePickList"
          >
            {{ pick.listOpen ? t('Collapse') : t('I need another account') }}
          </button>
        </div>
        <input type="hidden" name="account_id" id="login-code-account" :value="accountId">

        <div class="form-group">
          <label for="code">{{ t('Code') }}</label>
          <div class="input-with-button">
            <input
              id="code"
              v-model="form.code"
              type="text"
              name="code"
              required
              inputmode="numeric"
              autocomplete="one-time-code"
              :placeholder="t('Enter the 6-digit verification code')"
              maxlength="6"
            >
            <button
              type="button"
              id="send-code-btn"
              class="btn btn-secondary"
              :disabled="sending || countdown > 0"
              @click="onSendClick"
            >{{ sendCodeText }}</button>
          </div>
        </div>

        <div v-if="requireCaptcha" class="form-group">
          <label>{{ t('CAPTCHA') }}</label>
          <CaptchaInput
            v-model="form.captchaCode"
            input-id="code-captcha"
            img-id="code-captcha-img"
          />
          <p class="form-hint">{{ t('Too many sign-in attempts — enter the CAPTCHA') }}</p>
        </div>

        <button type="submit" class="btn btn-primary btn-block">{{ t('Sign in') }}</button>
      </form>

      <form class="auth-form" id="biometric-form" v-show="current === 'biometric'">
        <p class="login-biometric-tip">{{ t('Sign in with the fingerprint / face / Windows Hello bound to this device — no need to enter an account.') }}</p>

        <button
          type="button"
          class="btn btn-primary btn-block"
          id="biometric-login-btn"
          :disabled="biometricBusy"
          @click="biometricLogin"
        >
          <span id="biometric-btn-text">{{ biometricBtnText }}</span>
        </button>

        <div v-show="biometricStatus" id="biometric-status" class="login-biometric-status">{{ biometricStatus }}</div>

        <div v-show="biometricManual" class="login-biometric-manual" id="biometric-manual">
          <div class="form-group">
            <label for="biometric-identifier">{{ t('Username or sign-up email') }}</label>
            <input
              id="biometric-identifier"
              ref="biometricIdentifierEl"
              v-model="biometricIdentifier"
              type="text"
              :placeholder="t('Enter your username or sign-up email')"
              autocomplete="username"
            >
          </div>
          <p class="form-hint">{{ t('Once you enter an account, verification uses the credentials bound to it on this device') }}</p>
        </div>

        <button
          v-show="!biometricManual"
          type="button"
          class="login-link-btn"
          id="biometric-manual-toggle"
          @click="revealManual"
        >{{ t('No credential on this device? Enter a username or email manually') }}</button>
      </form>
    </div>

    <div v-show="triedMethods.length" class="login-tried" id="login-tried">
      <span class="login-tried__label">{{ t('Tried this time: ') }}</span>
      <span class="login-tried__items" id="login-tried-items">
        <button
          v-for="method in triedMethods"
          :key="method"
          type="button"
          class="login-tried__item"
          @click="showMethod(method)"
        >{{ labelFor(method) }}<span class="login-tried__retry">{{ t('Retry') }}</span></button>
      </span>
    </div>

    <div v-if="moreAvailable" class="login-more">
      <button
        type="button"
        class="login-more__toggle"
        id="login-more-toggle"
        :aria-expanded="moreOpen"
        aria-controls="login-more-list"
        @click="moreOpen = !moreOpen"
      >{{ t('More sign-in options') }}</button>
      <div v-show="moreOpen" class="login-more__list" id="login-more-list">
        <button v-if="props.methods.password" v-show="current !== 'password'" type="button" class="login-more__item" @click="showMethod('password')">{{ t('Password') }}</button>
        <button v-show="current !== 'email_code'" v-if="props.methods.email_code && mailEnabled" type="button" class="login-more__item" @click="showMethod('email_code')">{{ t('Email code') }}</button>
        <button v-if="props.methods.biometric && biometricSupported" v-show="current !== 'biometric'" type="button" class="login-more__item" @click="showMethod('biometric')">{{ t('Biometric') }}</button>
        <a v-if="props.methods.temp_key" class="login-more__item" href="/fast-pass">{{ t('Temp key sign-in') }}</a>
      </div>
    </div>

    <div v-if="props.methods.oauth && props.providers.length" class="auth-providers">
      <p class="auth-providers__label">{{ t('Or sign in with') }}</p>
      <div class="auth-providers__list">
        <a
          v-for="provider in props.providers"
          :key="provider.slug"
          class="auth-provider"
          :href="providerUrl(provider.slug)"
        >
          <span v-if="provider.logo" class="auth-provider__mark auth-provider__mark--logo"><img :src="provider.logo" alt=""></span>
          <span v-else class="auth-provider__mark">{{ provider.name.slice(0, 1) }}</span>
          <span>{{ provider.name }}</span>
        </a>
      </div>
    </div>

    <div class="auth-legal" v-if="props.legalLinks.length">
      <p>
        {{ t('By signing in, you agree to the') }}
        <template v-for="(link, i) in props.legalLinks" :key="link.url">
          <span v-if="i > 0"> · </span>
          <a :href="link.url" target="_blank">{{ t(link.label) }}</a>
        </template>
      </p>
    </div>

    <div class="auth-footer">
      <p>{{ t('Don\'t have an account?') }} <a href="/register">{{ t('Create one') }}</a></p>
      <p class="auth-footer__note">
        {{ t('Remember any one of your verification methods? Use it to pass the first step — once you reach the two-step verification page you can start online recovery') }}
      </p>
      <p class="auth-footer__note" v-if="props.supportEmail">
        {{ t('Need help? Contact support.') }}
        <a :href="'mailto:' + props.supportEmail">{{ props.supportEmail }}</a>
      </p>
    </div>
  </div>
</template>
