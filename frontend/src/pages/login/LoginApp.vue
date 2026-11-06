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
  privacyUrl: { type: String, default: '' },
  termsUrl: { type: String, default: '' },
  developerGuidelinesUrl: { type: String, default: '' },
  supportEmail: { type: String, default: '' },
  supportName: { type: String, default: '' },
  mailEnabled: { type: Boolean, default: true },
  brandLogo: { type: String, default: '/assets/brand/nomen-icon.svg' },
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
  if (method === 'email_code') return props.mailEnabled
  return method === 'biometric' ? biometricSupported.value : true
}

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
  rememberPassword: false,
  rememberCode: false,
  captchaPassword: '',
  captchaCode: '',
})

const accountId = ref('')
const sending = ref(false)
const countdown = ref(0)
let countdownTimer = null

const biometricIdentifier = ref(remembered?.u || '')
const biometricBusy = ref(false)
const biometricBtnText = ref(t('使用本机生物识别登录'))
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
  if (sending.value) return t('发送中...')
  if (countdown.value > 0) return t('{:n}秒后重试', { n: countdown.value })
  return t('发送验证码')
})

function labelFor(method) {
  return props.triedLabels[method] || method
}

function announceSwitch(method) {
  const allTried = ['password', 'email_code', 'biometric'].every((m) => tried[m] || !available(m))
  switchHint.value = allTried
    ? t('这几种登录方式本次都没成功。如果都进不去，可以按页面下方的说明发起账号恢复。')
    : t('已为你切换到「{:method}」，需要的话可以在下面「更多登录方式」里换回去。', { method: labelFor(method) })
}

function showMethod(method) {
  if (!available(method)) method = 'password'
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
  if (mode === 'address') return item.is_primary ? t('首选') : ''
  return item.as_primary ? t('主邮箱') : t('备选邮箱')
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
    toast(t('请输入邮箱或用户名'), 'warning')
    return
  }

  const identifierOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(identifier) || /^[a-z0-9_]{3,32}$/.test(identifier)
  if (!identifierOk) {
    toast(t('请输入有效的邮箱地址或用户名'), 'warning')
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
    toast(t('发送失败，请稍后重试'), 'error')
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
  biometricBtnText.value = t('使用本机生物识别登录')
  biometricStatus.value = ''
}

function onBiometricFail() {
  tried.biometric = true
  announceSwitch(showMethod(pickLoginMethod(tried, null, available)))
}

async function biometricLogin() {
  if (!biometricSupported.value) {
    toast(t('当前浏览器不支持生物识别登录'), 'warning')
    return
  }
  if (biometricBusy.value) return

  const identifier = biometricIdentifier.value.trim()
  const optionsUrl = urls.biometricOptions + (identifier ? '?identifier=' + encodeURIComponent(identifier) : '')

  biometricBusy.value = true
  biometricBtnText.value = t('准备中...')
  biometricStatus.value = ''

  try {
    const optionsResponse = await fetch(optionsUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    const data = await parseJsonResponse(optionsResponse)
    if (!data.success) {
      const error = new Error(data.message || t('无法开始生物识别验证'))
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

    biometricBtnText.value = t('请完成生物识别验证...')
    biometricStatus.value = t('正在调用系统生物识别（指纹 / 面容 / PIN）...')

    const credential = await navigator.credentials.get({ publicKey: options.publicKey })
    if (!credential) throw new Error('cancelled')

    const response = credential.response
    const verifyResponse = await fetch(urls.biometricVerify, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
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
      biometricStatus.value = t('没有完成验证。如果本机没有绑定过该账号的通行密钥，可以在下面填上用户名或邮箱再试。')
      revealManual()
      return
    }

    toast(error && error.message ? error.message : t('生物识别登录失败，请重试'), 'error')

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
      <h1>{{ t('登录 Nomen') }}</h1>
      <p>{{ t('使用您的 Nomen 账户继续') }}</p>
    </div>

    <BaseAlert v-if="error" type="error" :message="error" />
    <BaseAlert v-if="success" type="success" :message="success" />

    <p v-show="switchHint" class="login-switch-hint" id="login-switch-hint">{{ switchHint }}</p>

    <div class="login-primary">
      <form method="POST" action="/login" class="auth-form" id="password-form" v-show="current === 'password'">
        <input type="hidden" name="_csrf" :value="csrf">
        <input type="hidden" name="redirect_to" :value="redirectTo">

        <div class="form-group">
          <label for="username">{{ t('用户名或邮箱') }}</label>
          <input
            id="username"
            v-model="form.username"
            type="text"
            name="username"
            required
            :placeholder="t('请输入用户名或邮箱地址')"
          >
        </div>

        <div class="form-group">
          <label for="password">{{ t('密码') }}</label>
          <input
            id="password"
            v-model="form.password"
            type="password"
            name="password"
            required
            autocomplete="current-password"
            :placeholder="t('请输入密码')"
          >
        </div>

        <div class="form-group form-group--inline">
          <label class="checkbox-label">
            <input v-model="form.rememberPassword" type="checkbox" name="remember_me" value="1">
            <span class="checkmark"></span>
            <span>{{ t('记住我') }}</span>
          </label>
        </div>

        <div v-if="requireCaptcha" class="form-group">
          <label>{{ t('图片验证码') }}</label>
          <CaptchaInput
            v-model="form.captchaPassword"
            input-id="login-captcha"
            img-id="login-captcha-img"
          />
          <p class="form-hint">{{ t('检测到登录尝试较多，请输入图片验证码') }}</p>
        </div>

        <button type="submit" class="btn btn-primary btn-block">{{ t('登录') }}</button>
      </form>

      <form method="POST" action="/login/code" class="auth-form" id="code-form" v-show="current === 'email_code'">
        <input type="hidden" name="_csrf" :value="csrf">
        <input type="hidden" name="redirect_to" :value="redirectTo">

        <div class="form-group">
          <label for="email">{{ t('邮箱或用户名') }}</label>
          <input
            id="email"
            v-model="form.email"
            type="text"
            name="email"
            required
            autocomplete="username"
            :placeholder="t('注册邮箱，或你的用户名')"
            @input="onEmailInput"
          >
          <p class="form-hint">{{ t('填哪个邮箱就发到哪个（账号绑定的邮箱都能用）；填用户名会让你选发到哪个邮箱，未成年账号会发到监护人邮箱') }}</p>
        </div>

        <div v-show="pick.visible" class="login-account-pick" id="login-account-pick">
          <p class="login-account-pick__tip" id="login-account-pick-tip">
            {{ pick.mode === 'address' ? t('这个账号绑了多个邮箱，请选择发到哪一个') : t('这个邮箱对应多个账号，请选择要登录哪一个') }}
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
            {{ pick.listOpen ? t('收起') : t('我需要另一个账号') }}
          </button>
        </div>
        <input type="hidden" name="account_id" id="login-code-account" :value="accountId">

        <div class="form-group">
          <label for="code">{{ t('验证码') }}</label>
          <div class="input-with-button">
            <input
              id="code"
              v-model="form.code"
              type="text"
              name="code"
              required
              inputmode="numeric"
              autocomplete="one-time-code"
              :placeholder="t('请输入6位验证码')"
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

        <div class="form-group form-group--inline">
          <label class="checkbox-label">
            <input v-model="form.rememberCode" type="checkbox" name="remember_me" value="1">
            <span class="checkmark"></span>
            <span>{{ t('记住我') }}</span>
          </label>
        </div>

        <div v-if="requireCaptcha" class="form-group">
          <label>{{ t('图片验证码') }}</label>
          <CaptchaInput
            v-model="form.captchaCode"
            input-id="code-captcha"
            img-id="code-captcha-img"
          />
          <p class="form-hint">{{ t('检测到登录尝试较多，请输入图片验证码') }}</p>
        </div>

        <button type="submit" class="btn btn-primary btn-block">{{ t('登录') }}</button>
      </form>

      <form class="auth-form" id="biometric-form" v-show="current === 'biometric'">
        <p class="login-biometric-tip">{{ t('用本机已绑定的指纹 / 面容 / Windows Hello 直接登录，不用输账号。') }}</p>

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
            <label for="biometric-identifier">{{ t('用户名或注册邮箱') }}</label>
            <input
              id="biometric-identifier"
              ref="biometricIdentifierEl"
              v-model="biometricIdentifier"
              type="text"
              :placeholder="t('请输入用户名或注册邮箱')"
              autocomplete="username"
            >
          </div>
          <p class="form-hint">{{ t('填上账号后，会按这个账号在本机已绑定的凭证来验证') }}</p>
        </div>

        <button
          v-show="!biometricManual"
          type="button"
          class="login-link-btn"
          id="biometric-manual-toggle"
          @click="revealManual"
        >{{ t('本机没找到凭证？手动输入用户名或邮箱') }}</button>
      </form>
    </div>

    <div v-show="triedMethods.length" class="login-tried" id="login-tried">
      <span class="login-tried__label">{{ t('本次已尝试：') }}</span>
      <span class="login-tried__items" id="login-tried-items">
        <button
          v-for="method in triedMethods"
          :key="method"
          type="button"
          class="login-tried__item"
          @click="showMethod(method)"
        >{{ labelFor(method) }}<span class="login-tried__retry">{{ t('重试') }}</span></button>
      </span>
    </div>

    <div class="login-more">
      <button
        type="button"
        class="login-more__toggle"
        id="login-more-toggle"
        :aria-expanded="moreOpen"
        aria-controls="login-more-list"
        @click="moreOpen = !moreOpen"
      >{{ t('更多登录方式') }}</button>
      <div v-show="moreOpen" class="login-more__list" id="login-more-list">
        <button v-show="current !== 'password'" type="button" class="login-more__item" @click="showMethod('password')">{{ t('密码登录') }}</button>
        <button v-show="current !== 'email_code'" v-if="mailEnabled" type="button" class="login-more__item" @click="showMethod('email_code')">{{ t('邮箱验证码登录') }}</button>
        <button v-if="biometricSupported" v-show="current !== 'biometric'" type="button" class="login-more__item" @click="showMethod('biometric')">{{ t('生物识别登录') }}</button>
        <a class="login-more__item" href="/fast-pass">{{ t('临时密钥登录') }}</a>
      </div>
    </div>

    <div class="auth-legal" v-if="props.privacyUrl || props.termsUrl || props.developerGuidelinesUrl">
      <p v-if="props.privacyUrl || props.termsUrl">
        {{ t('登录即代表同意') }}
        <template v-if="props.privacyUrl"><a :href="props.privacyUrl" target="_blank">{{ t('隐私政策') }}</a></template>
        <template v-if="props.privacyUrl && props.termsUrl">{{ t('和') }}</template>
        <template v-if="props.termsUrl"><a :href="props.termsUrl" target="_blank">{{ t('服务协议') }}</a></template>
      </p>
      <p v-if="props.developerGuidelinesUrl">
        {{ t('开发者接入时还需遵守') }}
        <a :href="props.developerGuidelinesUrl" target="_blank">{{ t('开发者守则') }}</a>
      </p>
    </div>

    <div class="auth-footer">
      <p>{{ t('还没有账户？') }} <a href="/register">{{ t('注册一个') }}</a></p>
      <p class="auth-footer__note">
        {{ t('还记得其中任意一种验证方式？可以先用它通过第一步，进入两步验证页后即可发起在线恢复流程') }}
      </p>
      <p class="auth-footer__note" v-if="props.supportEmail">
        {{ t('遇到问题了？联系支持团队') }}
        <a :href="'mailto:' + props.supportEmail">{{ props.supportEmail }}</a>
      </p>
    </div>
  </div>
</template>
