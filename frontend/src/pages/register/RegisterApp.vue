<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useT } from '../../composables/useT.js'
const { t } = useT()
import BaseAlert from '../../components/BaseAlert.vue'
import BaseButton from '../../components/BaseButton.vue'
import CaptchaInput from '../../components/CaptchaInput.vue'
import CountryPicker from '../../components/CountryPicker.vue'
import Icon from '../../components/Icon.vue'
import PasswordInput from '../../components/PasswordInput.vue'
import { copyText, generateStrongPassword, passwordStrength } from '../../composables/usePasswordTools.js'
import { toast } from '../../lib/toast.js'
import RegisterProgress from './RegisterProgress.vue'
import StepHero from './StepHero.vue'

const props = defineProps({
  csrf: { type: String, default: '' },
  error: { type: String, default: '' },
  old: { type: [Object, Array], default: () => ({}) },
  country: { type: String, default: '' },
  autoCountry: { type: Boolean, default: false },
  today: { type: String, default: '' },
  countries: { type: Array, default: () => [] },
  features: { type: Object, default: () => ({ country: false, guardians: false }) },
  privacyUrl: { type: String, default: '' },
  termsUrl: { type: String, default: '' },
  supportEmail: { type: String, default: '' },
  brandLogo: { type: String, default: '/assets/brand/nomen-icon.svg' },
  providers: { type: Array, default: () => [] },
  passwordRegistration: { type: Boolean, default: true },
  urls: { type: Object, default: () => ({}) },
})

const featureCountry = props.features?.country === true
const featureGuardians = props.features?.guardians === true

const steps = featureCountry
  ? ['account', 'password', 'country', 'profile', 'email']
  : ['account', 'password', 'profile', 'email']
const totalSteps = steps.length
// 进度条从「国家」那一步开始显示；没有国家步骤就从「关于你」开始
const progressFromStep = featureCountry ? steps.indexOf('country') + 1 : steps.indexOf('profile') + 1
const stepNo = (id) => steps.indexOf(id) + 1

const old = Array.isArray(props.old) ? {} : (props.old || {})
const hasOldInput = Object.keys(old).length > 0

const urls = {
  consentAge: props.urls?.consentAge || '/api/consent-age',
  checkUsername: props.urls?.checkUsername || '/api/check-username',
  captcha: props.urls?.captcha || '/captcha',
}

const form = reactive({
  username: old.username || '',
  password: '',
  password_confirm: '',
  country: props.country || '',
  birth_date: old.birth_date || '',
  gender: old.gender || '',
  email: old.email || '',
  parent_email: old.parent_email || '',
  captcha: '',
  agree_terms: !!old.agree_terms,
  agree_service: !!old.agree_service,
})

function clamp(value, min, max) {
  return Math.min(Math.max(value, min), max)
}

const currentStep = ref(clamp(parseInt(old.current_step, 10) || 1, 1, totalSteps))

const genders = [
  { id: 'female', value: 'female', label: t('Female') },
  { id: 'male', value: 'male', label: t('Male') },
  { id: 'nonbinary', value: 'non-binary', label: t('Non-binary') },
  { id: 'prefer-not', value: 'prefer-not-to-say', label: t('Prefer not to say') },
]

const usernameState = ref('')
const usernameHint = ref(t('Lowercase letters, digits and underscore, 3-32 characters'))
const usernameHintType = ref('')
const usernameHintIcon = ref('bulb')
const usernameStateIcon = computed(() => ({
  ok: 'check',
  bad: 'close',
  loading: 'refresh',
}[usernameState.value] || ''))

let usernameTimer = null
let usernameController = null

function setUsernameHint(text, type = '', icon = '') {
  usernameHint.value = text
  usernameHintType.value = type
  usernameHintIcon.value = icon
}

watch(() => form.username, (value) => {
  const username = value.trim()
  clearTimeout(usernameTimer)

  if (usernameController) {
    usernameController.abort()
    usernameController = null
  }

  if (!username) {
    usernameState.value = ''
    setUsernameHint(t('Lowercase letters, digits and underscore, 3-32 characters'), '', 'bulb')
    return
  }

  if (!/^[a-z0-9_]{3,32}$/.test(username)) {
    usernameState.value = 'bad'
    setUsernameHint(t('Username: lowercase letters, digits, underscore; 3-32 characters'), 'error')
    return
  }

  usernameState.value = 'loading'
  setUsernameHint(t('Checking...'))

  usernameTimer = setTimeout(async () => {
    const controller = new AbortController()
    usernameController = controller
    try {
      const res = await fetch(`${urls.checkUsername}?username=${encodeURIComponent(username)}`, { signal: controller.signal })
      const data = await res.json()
      if (controller.signal.aborted) return
      if (data.available) {
        usernameState.value = 'ok'
        setUsernameHint(data.message, 'success')
      } else {
        usernameState.value = 'bad'
        setUsernameHint(data.message, 'error')
      }
    } catch (err) {
      if (err.name === 'AbortError') return
      usernameState.value = ''
      setUsernameHint(t('Check failed, try again'), 'error')
    }
  }, 500)
})

const strength = ref({ label: '', color: '', width: '0%' })
const passwordRevealed = ref(false)
const confirmRevealed = ref(false)

watch(() => form.password, (value) => {
  strength.value = passwordStrength(value)
})

const strengthText = computed(() => (strength.value.label ? t('Strength: {:label}', { label: strength.value.label }) : t('Password strength')))

const passwordMatchState = computed(() => {
  if (!form.password_confirm) return ''
  return form.password === form.password_confirm ? 'ok' : 'bad'
})

async function generatePassword() {
  const password = generateStrongPassword(16)
  form.password = password
  form.password_confirm = password
  passwordRevealed.value = true
  confirmRevealed.value = true
  await nextTick()
  document.getElementById('password')?.focus()

  const ok = await copyText(password)
  toast(
    ok
      ? t('Strong password generated and copied. Paste it into your password manager, then clear the clipboard.')
      : t('Strong password generated. Select and copy it in the field, and clear your clipboard afterwards.'),
    ok ? 'success' : 'warning',
    6000,
  )
}

const isChild = ref(false)
const parentVisible = ref(false)
const consentInfo = ref(null)
const showCountryHint = ref(props.autoCountry)
const consentCache = new Map()
const consentPending = new Map()

function calcAge(birthDate) {
  const parts = birthDate.split('-')
  if (parts.length !== 3) return null
  const birth = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10))
  const today = new Date()
  let age = today.getFullYear() - birth.getFullYear()
  if (today.getMonth() < birth.getMonth() || (today.getMonth() === birth.getMonth() && today.getDate() < birth.getDate())) {
    age--
  }
  return age
}

function fetchConsentAge(code) {
  if (consentCache.has(code)) return Promise.resolve(consentCache.get(code))
  if (consentPending.has(code)) return consentPending.get(code)

  const promise = fetch(`${urls.consentAge}?country=${encodeURIComponent(code)}`)
    .then((res) => res.json())
    .then((data) => {
      consentPending.delete(code)
      if (!data || typeof data.age !== 'number') throw new Error('bad payload')
      consentCache.set(code, data)
      return data
    })
    .catch((err) => {
      consentPending.delete(code)
      throw err
    })

  consentPending.set(code, promise)
  return promise
}

function applyConsentRule(child, info) {
  const minor = featureGuardians && child
  isChild.value = minor
  parentVisible.value = minor
  consentInfo.value = minor ? info : null
}

function refreshConsentRule() {
  if (!featureGuardians) {
    applyConsentRule(false, null)
    return
  }

  const birthDate = form.birth_date
  if (!birthDate) {
    applyConsentRule(false, null)
    return
  }

  const age = calcAge(birthDate)
  if (age === null) {
    applyConsentRule(false, null)
    return
  }

  // 国家选择关掉时按平台默认年龄算，请求不带 country
  const code = featureCountry ? form.country : ''
  if (featureCountry && !code) {
    applyConsentRule(false, null)
    return
  }

  if (consentCache.has(code)) {
    const info = consentCache.get(code)
    applyConsentRule(age < info.age, info)
    return
  }

  applyConsentRule(true, null)
  fetchConsentAge(code)
    .then((info) => applyConsentRule(age < info.age, info))
    .catch(() => {})
}

watch(() => form.birth_date, refreshConsentRule)

const parentRequired = computed(() => parentVisible.value && !form.email.trim())

const consentSourceText = computed(() => {
  const info = consentInfo.value
  if (!info) return ''
  if (!info.country) {
    return t('The platform treats registrations under {:age} as requiring guardian consent.', { age: info.age })
  }
  const hasStatute = info.kind === 'statute' || info.kind === 'advisory'
  if (hasStatute) {
    return t('In your region, children under {:age} need parental consent (basis: {:label}{:suffix})', {
      age: info.age,
      label: info.label,
      suffix: info.kind === 'advisory' ? t(', local regulator guidance') : '',
    })
  }
  return t('Under the site privacy policy, this region is handled as {:age}.', { age: info.age })
})

const consentSourceLink = computed(() => {
  const info = consentInfo.value
  if (!info) return null
  if (info.url) return { href: info.url, text: t('Official source ↗') }
  if (info.policy_url) return { href: info.policy_url, text: t('Privacy Policy ↗') }
  return null
})

function onCountryPicked() {
  showCountryHint.value = false
  refreshConsentRule()
}

function validateCurrentStep() {
  switch (steps[currentStep.value - 1]) {
    case 'account': {
      const username = form.username.trim()
      if (!username) {
        toast(t('Enter a username'), 'warning')
        return false
      }
      if (!/^[a-zA-Z0-9_]{3,32}$/.test(username)) {
        toast(t('Invalid username format'), 'error')
        return false
      }
      if (usernameState.value === 'bad') {
        toast(t('Username unavailable'), 'error')
        return false
      }
      return true
    }
    case 'password':
      if (!form.password || form.password.length < 8) {
        toast(t('Password needs at least 8 characters'), 'warning')
        return false
      }
      if (!/[a-zA-Z]/.test(form.password) || !/[0-9]/.test(form.password)) {
        toast(t('Password needs both letters and digits'), 'warning')
        return false
      }
      if (form.password !== form.password_confirm) {
        toast(t('The passwords you entered don\'t match'), 'error')
        return false
      }
      return true
    case 'country':
      if (!form.country) {
        toast(t('Pick a country/region'), 'warning')
        return false
      }
      return true
    case 'profile':
      if (!form.birth_date) {
        toast(t('Pick a date of birth'), 'warning')
        return false
      }
      if (!form.gender) {
        toast(t('Pick a gender'), 'warning')
        return false
      }
      return true
    default:
      return true
  }
}

function nextStep() {
  if (!validateCurrentStep()) return
  const id = steps[currentStep.value - 1]
  if (id === 'country' || id === 'profile') {
    refreshConsentRule()
  }
  currentStep.value++
}

function prevStep() {
  currentStep.value--
}

onMounted(() => {
  if (currentStep.value >= stepNo('profile')) {
    refreshConsentRule()
  }
  if (currentStep.value === stepNo('account')) {
    nextTick(() => document.getElementById('username')?.focus())
  }
})
</script>

<template>
  <div class="auth-card auth-card--register">
    <div class="auth-header">
        <div class="auth-logo">
          <img :src="brandLogo" alt="" width="48" height="48">
        </div>
        <h1>{{ t('Sign up for {:sitename}') }}</h1>
        <p>{{ t('Create your own account') }}</p>
      </div>

      <BaseAlert v-if="error" type="error" :message="error" />

      <RegisterProgress v-if="props.passwordRegistration" :current="currentStep" :total="totalSteps" :from="progressFromStep" />

      <div v-if="!props.passwordRegistration" class="register-oauth-only">
        <p class="form-hint">{{ t('Password sign-in is turned off on this site.') }}</p>
        <p class="form-hint">{{ t('Use one of the options below to create your account.') }}</p>
      </div>

      <form v-else method="POST" action="/register" class="auth-form" id="register-form" novalidate>
        <input type="hidden" name="_csrf" :value="csrf">
        <input type="hidden" name="current_step" :value="currentStep">

        <div :class="['form-step', { active: currentStep === 1 }]" id="step-1">
          <StepHero icon="user" :title="t('Pick a name for yourself')" :desc="t('This is your unique ID — other users will see this name')" />

          <div class="form-group form-group--username">
            <label for="username">{{ t('Username') }}</label>
            <div class="input-wrapper input-wrapper--large">
              <input
                id="username"
                v-model="form.username"
                type="text"
                name="username"
                required
                :placeholder="t('Enter the username you want')"
                pattern="[a-z0-9_]{3,32}"
              >
              <span class="input-status" id="username-status" :data-state="usernameState">
                <Icon v-if="usernameState" :name="usernameStateIcon" />
              </span>
            </div>
            <div :class="['form-hint', usernameHintType]" id="username-hint">
              <span v-if="usernameHintIcon" :class="['hint-icon', 'icon', 'icon-' + usernameHintIcon]"></span>
              {{ usernameHint }}
            </div>
          </div>
        </div>

        <div :class="['form-step', { active: currentStep === 2 }]" id="step-2">
          <StepHero icon="lock" :title="t('Set a password')" :desc="t('Set a secure password to protect your account')" />

          <div class="form-group">
            <label for="password">{{ t('Password') }}</label>
            <p v-if="hasOldInput" class="form-hint form-hint--spaced">{{ t('For security, the password isn\'t kept on the page — enter it again') }}</p>
            <PasswordInput
              id="password"
              name="password"
              v-model="form.password"
              v-model:revealed="passwordRevealed"
              :placeholder="t('8+ characters, letters and digits')"
              minlength="8"
              required
            />
            <div class="pw-strength">
              <div class="pw-strength__bar">
                <div class="pw-strength__fill" :style="{ width: strength.width, background: strength.color }"></div>
              </div>
              <span class="pw-strength__text" :style="{ color: strength.color }">{{ strengthText }}</span>
              <button type="button" class="pw-gen" :title="t('Generate a strong random password')" @mousedown.prevent @click="generatePassword">{{ t('Generate') }}</button>
            </div>
          </div>

          <div class="form-group">
            <label for="password_confirm">{{ t('Confirm password') }}</label>
            <PasswordInput
              id="password_confirm"
              name="password_confirm"
              v-model="form.password_confirm"
              v-model:revealed="confirmRevealed"
              :placeholder="t('Re-enter password')"
              required
              :show-toggle="false"
            >
              <template #status>
                <span class="input-status" id="password-match-status" :data-state="passwordMatchState">
                  <Icon v-if="passwordMatchState" :name="passwordMatchState === 'ok' ? 'check' : 'close'" />
                </span>
              </template>
            </PasswordInput>
          </div>
        </div>

        <div v-if="featureCountry" :class="['form-step', { active: currentStep === stepNo('country') }]" id="step-3">
          <StepHero icon="globe" :title="t('Where are you?')" :desc="t('Select your country or region')" />

          <div class="form-group">
            <label for="country-search">{{ t('Country / Region') }}</label>
            <CountryPicker
              v-model="form.country"
              :countries="countries"
              @picked="onCountryPicked"
              @interacted="showCountryHint = false"
            />
            <small v-if="showCountryHint" class="form-hint" id="country-hint">{{ t('Filled in from your network location — you can change it') }}</small>
          </div>
        </div>

        <div :class="['form-step', { active: currentStep === stepNo('profile') }]" id="step-4">
          <StepHero icon="cake" :title="t('About you')" :desc="t('Tell us a bit about yourself — this stays private')" />

          <div class="form-group">
            <label for="birth_date">{{ t('Date of birth') }}</label>
            <input
              id="birth_date"
              v-model="form.birth_date"
              type="date"
              name="birth_date"
              required
              min="1920-01-01"
              :max="today"
            >
          </div>

          <div class="form-group">
            <label>{{ t('What\'s your gender?') }}</label>
            <div class="gender-radio-group">
              <div v-for="option in genders" :key="option.id" class="gender-radio-row">
                <input
                  :id="'gender-' + option.id"
                  v-model="form.gender"
                  type="radio"
                  name="gender"
                  :value="option.value"
                  :required="option.id === 'female'"
                >
                <label :for="'gender-' + option.id">{{ option.label }}</label>
              </div>
            </div>
          </div>
        </div>

        <div :class="['form-step', { active: currentStep === stepNo('email') }]" id="step-5">
          <StepHero icon="mail" :title="t('Verify email')" :desc="t('For important notifications and account recovery')" />

          <div class="form-group">
            <label for="email" id="email-label">{{ isChild ? t('Your own email (optional)') : t('Email addresses') }}</label>
            <input
              id="email"
              v-model="form.email"
              type="email"
              name="email"
              placeholder="you@domain.com"
              :required="!isChild"
            >
            <small class="form-hint" id="email-hint">{{ isChild ? t('Use your own if you have one; otherwise leave empty and fill in the parent email below') : t('For important notifications and account recovery') }}</small>
          </div>

          <div v-if="featureGuardians && parentVisible" class="form-group" id="parent-email-group">
            <label for="parent_email">{{ t('Parent\'s email') }}</label>
            <input
              id="parent_email"
              v-model="form.parent_email"
              type="email"
              name="parent_email"
              :placeholder="t('No email of your own? Use a parent\'s')"
              :required="parentRequired"
            >
            <small class="form-hint">
              {{ t('The account uses this address to receive verification email. The account can\'t be used until a parent clicks the confirmation link.') }}
              {{ t('After confirming, the parent can also join your family and later reset your password or temporarily lock the account.') }}
              {{ t('One parent\'s email can cover multiple children.') }}
            </small>
            <p v-if="isChild && consentInfo" class="consent-source" id="consent-source">
              {{ consentSourceText }} <a v-if="consentSourceLink" :href="consentSourceLink.href" target="_blank" rel="noopener noreferrer">{{ consentSourceLink.text }}</a>
            </p>
          </div>

          <div class="form-group">
            <label>{{ t('Code') }}</label>
            <CaptchaInput v-model="form.captcha" :url="urls.captcha" />
          </div>

          <div class="agreement-section">
            <div class="form-group form-group--inline">
              <label class="checkbox-label">
                <input v-model="form.agree_terms" type="checkbox" name="agree_terms" value="1" required>
                <span class="checkmark"></span>
                <span>{{ t('I have read and agree to the «') }}<a v-if="props.privacyUrl" :href="props.privacyUrl" target="_blank">{{ t('Privacy Policy') }}</a><template v-else>{{ t('Privacy Policy') }}</template>{{ t('»') }}</span>
              </label>
            </div>
            <div class="form-group form-group--inline">
              <label class="checkbox-label">
                <input v-model="form.agree_service" type="checkbox" name="agree_service" value="1" required>
                <span class="checkmark"></span>
                <span>{{ t('I have read and agree to the «') }}<a v-if="props.termsUrl" :href="props.termsUrl" target="_blank">{{ t('Terms of Service') }}</a><template v-else>{{ t('Terms of Service') }}</template>{{ t('»') }}</span>
              </label>
            </div>
          </div>
        </div>

        <div class="form-navigation">
          <BaseButton v-if="currentStep > 1" id="btn-prev" variant="secondary" @click="prevStep">{{ t('Previous') }}</BaseButton>
          <BaseButton v-if="currentStep < totalSteps" id="btn-next" @click="nextStep">{{ t('Next') }}</BaseButton>
          <BaseButton v-if="currentStep === totalSteps" id="btn-submit" type="submit" block>{{ t('Finish sign-up') }}</BaseButton>
        </div>
      </form>

      <div v-if="props.providers.length" class="auth-providers">
        <p class="auth-providers__label">{{ t('Or sign up with') }}</p>
        <div class="auth-providers__list">
          <a
            v-for="provider in props.providers"
            :key="provider.slug"
            class="auth-provider"
            :href="`/auth/${provider.slug}/redirect`"
          >
            <span v-if="provider.logo" class="auth-provider__mark auth-provider__mark--logo"><img :src="provider.logo" alt=""></span>
            <span v-else class="auth-provider__mark">{{ provider.name.slice(0, 1) }}</span>
            <span>{{ provider.name }}</span>
          </a>
        </div>
      </div>

      <div class="auth-footer">
        <p>{{ t('Already have an account?') }} <a href="/login">{{ t('Sign in') }}</a></p>
        <p class="auth-footer__note">{{ t('Email taken or need to recover an account? Go to the ') }}<a href="/login">{{ t('sign-in page') }}</a><template v-if="props.supportEmail">{{ t(' and sign in with an email code. Still locked out? Email ') }} <a :href="'mailto:' + props.supportEmail">{{ props.supportEmail }}</a></template><template v-else>{{ t('Sign in to the existing account with an email code') }}</template></p>
        <p v-if="featureGuardians" class="auth-footer__note">{{ t('Still below the child age for your region, or still in a family? After signing in, go to the ') }}<a href="/family">{{ t('Family') }}</a>{{ t(' page to see the supervision and independence requirements.') }}</p>
      </div>
    </div>
</template>
