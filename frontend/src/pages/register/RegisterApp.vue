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
  { id: 'female', value: 'female', label: t('女性') },
  { id: 'male', value: 'male', label: t('男性') },
  { id: 'nonbinary', value: 'non-binary', label: t('非二元性别') },
  { id: 'prefer-not', value: 'prefer-not-to-say', label: t('我宁愿不说') },
]

const usernameState = ref('')
const usernameHint = ref(t('支持小写字母、数字和下划线，3-32个字符'))
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
    setUsernameHint(t('支持小写字母、数字和下划线，3-32个字符'), '', 'bulb')
    return
  }

  if (!/^[a-z0-9_]{3,32}$/.test(username)) {
    usernameState.value = 'bad'
    setUsernameHint(t('用户名只能包含小写字母、数字和下划线，3-32个字符'), 'error')
    return
  }

  usernameState.value = 'loading'
  setUsernameHint(t('检查中...'))

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
      setUsernameHint(t('检查失败，请重试'), 'error')
    }
  }, 500)
})

const strength = ref({ label: '', color: '', width: '0%' })
const passwordRevealed = ref(false)
const confirmRevealed = ref(false)

watch(() => form.password, (value) => {
  strength.value = passwordStrength(value)
})

const strengthText = computed(() => (strength.value.label ? t('密码强度：{:label}', { label: strength.value.label }) : t('密码强度')))

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
      ? t('已生成强密码并复制。粘贴到密码管理器后，请清空剪贴板。')
      : t('已生成强密码。请在密码框中全选复制，并注意清空剪贴板。'),
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
    return t('平台默认把 {:age} 岁以下的注册视为需要家长同意', { age: info.age })
  }
  const hasStatute = info.kind === 'statute' || info.kind === 'advisory'
  if (hasStatute) {
    return t('你所在地区把 {:age} 岁以下的儿童视为需要家长同意（依据：{:label}{:suffix}）', {
      age: info.age,
      label: info.label,
      suffix: info.kind === 'advisory' ? t('，当地监管指引') : '',
    })
  }
  return t('依据站点隐私政策，本地区按 {:age} 岁处理', { age: info.age })
})

const consentSourceLink = computed(() => {
  const info = consentInfo.value
  if (!info) return null
  if (info.url) return { href: info.url, text: t('官方来源 ↗') }
  if (info.policy_url) return { href: info.policy_url, text: t('隐私权政策 ↗') }
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
        toast(t('请输入用户名'), 'warning')
        return false
      }
      if (!/^[a-zA-Z0-9_]{3,32}$/.test(username)) {
        toast(t('用户名格式不正确'), 'error')
        return false
      }
      if (usernameState.value === 'bad') {
        toast(t('用户名不可用'), 'error')
        return false
      }
      return true
    }
    case 'password':
      if (!form.password || form.password.length < 8) {
        toast(t('密码至少需要8位'), 'warning')
        return false
      }
      if (!/[a-zA-Z]/.test(form.password) || !/[0-9]/.test(form.password)) {
        toast(t('密码必须包含字母和数字'), 'warning')
        return false
      }
      if (form.password !== form.password_confirm) {
        toast(t('两次输入的密码不一致'), 'error')
        return false
      }
      return true
    case 'country':
      if (!form.country) {
        toast(t('请选择国家/地区'), 'warning')
        return false
      }
      return true
    case 'profile':
      if (!form.birth_date) {
        toast(t('请选择出生日期'), 'warning')
        return false
      }
      if (!form.gender) {
        toast(t('请选择性别'), 'warning')
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
        <h1>{{ t('注册 Nomen') }}</h1>
        <p>{{ t('创建你的专属账户') }}</p>
      </div>

      <BaseAlert v-if="error" type="error" :message="error" />

      <RegisterProgress :current="currentStep" :total="totalSteps" :from="progressFromStep" />

      <form method="POST" action="/register" class="auth-form" id="register-form" novalidate>
        <input type="hidden" name="_csrf" :value="csrf">
        <input type="hidden" name="current_step" :value="currentStep">

        <div :class="['form-step', { active: currentStep === 1 }]" id="step-1">
          <StepHero icon="user" :title="t('给自己起个名字吧')" :desc="t('这将是你的唯一标识，其他用户会看到这个名字')" />

          <div class="form-group form-group--username">
            <label for="username">{{ t('用户名') }}</label>
            <div class="input-wrapper input-wrapper--large">
              <input
                id="username"
                v-model="form.username"
                type="text"
                name="username"
                required
                :placeholder="t('输入你想要的用户名')"
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
          <StepHero icon="lock" :title="t('设置密码')" :desc="t('请设置一个安全的密码，保护你的账户')" />

          <div class="form-group">
            <label for="password">{{ t('密码') }}</label>
            <p v-if="hasOldInput" class="form-hint form-hint--spaced">{{ t('出于安全考虑，密码不会保留在页面上，请重新输入一次') }}</p>
            <PasswordInput
              id="password"
              name="password"
              v-model="form.password"
              v-model:revealed="passwordRevealed"
              :placeholder="t('至少8位，包含字母和数字')"
              minlength="8"
              required
            />
            <div class="pw-strength">
              <div class="pw-strength__bar">
                <div class="pw-strength__fill" :style="{ width: strength.width, background: strength.color }"></div>
              </div>
              <span class="pw-strength__text" :style="{ color: strength.color }">{{ strengthText }}</span>
              <button type="button" class="pw-gen" :title="t('生成一个随机强密码')" @mousedown.prevent @click="generatePassword">{{ t('生成') }}</button>
            </div>
          </div>

          <div class="form-group">
            <label for="password_confirm">{{ t('确认密码') }}</label>
            <PasswordInput
              id="password_confirm"
              name="password_confirm"
              v-model="form.password_confirm"
              v-model:revealed="confirmRevealed"
              :placeholder="t('再次输入密码')"
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
          <StepHero icon="globe" :title="t('你在哪？')" :desc="t('选择你所在的国家或地区')" />

          <div class="form-group">
            <label for="country-search">{{ t('国家 / 地区') }}</label>
            <CountryPicker
              v-model="form.country"
              :countries="countries"
              @picked="onCountryPicked"
              @interacted="showCountryHint = false"
            />
            <small v-if="showCountryHint" class="form-hint" id="country-hint">{{ t('已按你的网络位置填好，可以修改') }}</small>
          </div>
        </div>

        <div :class="['form-step', { active: currentStep === stepNo('profile') }]" id="step-4">
          <StepHero icon="cake" :title="t('关于你')" :desc="t('告诉我们一些关于你的信息，这些信息将被保密')" />

          <div class="form-group">
            <label for="birth_date">{{ t('出生日期') }}</label>
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
            <label>{{ t('你的性别是什么？') }}</label>
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
          <StepHero icon="mail" :title="t('验证邮箱')" :desc="t('用于接收重要通知和找回密码')" />

          <div class="form-group">
            <label for="email" id="email-label">{{ isChild ? t('你自己的邮箱（可选）') : t('邮箱地址') }}</label>
            <input
              id="email"
              v-model="form.email"
              type="email"
              name="email"
              placeholder="you@domain.com"
              :required="!isChild"
            >
            <small class="form-hint" id="email-hint">{{ isChild ? t('有就填自己的；没有就留空，填下面的家长邮箱') : t('用于接收重要通知和找回密码') }}</small>
          </div>

          <div v-if="featureGuardians && parentVisible" class="form-group" id="parent-email-group">
            <label for="parent_email">{{ t('家长邮箱') }}</label>
            <input
              id="parent_email"
              v-model="form.parent_email"
              type="email"
              name="parent_email"
              :placeholder="t('没有自己的邮箱就填家长的')"
              :required="parentRequired"
            >
            <small class="form-hint">
              {{ t('账号要用这个地址收验证邮件，家长点邮件里的确认链接后账号才能开始使用。') }}
              {{ t('家长确认后也可以加入你的家庭，之后能帮你重置密码、临时锁定账号。') }}
              {{ t('一个家长邮箱可以带多个孩子。') }}
            </small>
            <p v-if="isChild && consentInfo" class="consent-source" id="consent-source">
              {{ consentSourceText }} <a v-if="consentSourceLink" :href="consentSourceLink.href" target="_blank" rel="noopener noreferrer">{{ consentSourceLink.text }}</a>
            </p>
          </div>

          <div class="form-group">
            <label>{{ t('验证码') }}</label>
            <CaptchaInput v-model="form.captcha" :url="urls.captcha" />
          </div>

          <div class="agreement-section">
            <div class="form-group form-group--inline">
              <label class="checkbox-label">
                <input v-model="form.agree_terms" type="checkbox" name="agree_terms" value="1" required>
                <span class="checkmark"></span>
                <span>{{ t('我已阅读并同意《') }}<a v-if="props.privacyUrl" :href="props.privacyUrl" target="_blank">{{ t('隐私政策') }}</a><template v-else>{{ t('隐私政策') }}</template>{{ t('》') }}</span>
              </label>
            </div>
            <div class="form-group form-group--inline">
              <label class="checkbox-label">
                <input v-model="form.agree_service" type="checkbox" name="agree_service" value="1" required>
                <span class="checkmark"></span>
                <span>{{ t('我已阅读并同意《') }}<a v-if="props.termsUrl" :href="props.termsUrl" target="_blank">{{ t('服务协议') }}</a><template v-else>{{ t('服务协议') }}</template>{{ t('》') }}</span>
              </label>
            </div>
          </div>
        </div>

        <div class="form-navigation">
          <BaseButton v-if="currentStep > 1" id="btn-prev" variant="secondary" @click="prevStep">{{ t('上一步') }}</BaseButton>
          <BaseButton v-if="currentStep < totalSteps" id="btn-next" @click="nextStep">{{ t('下一步') }}</BaseButton>
          <BaseButton v-if="currentStep === totalSteps" id="btn-submit" type="submit" block>{{ t('完成注册') }}</BaseButton>
        </div>
      </form>

      <div class="auth-footer">
        <p>{{ t('已有账户？') }} <a href="/login">{{ t('登录') }}</a></p>
        <p class="auth-footer__note">{{ t('邮箱被占用，或想找回账号？到') }}<a href="/login">{{ t('登录页') }}</a><template v-if="props.supportEmail">{{ t('用「邮箱验证码」登录已有账户；还是进不去就发邮件到') }} <a :href="'mailto:' + props.supportEmail">{{ props.supportEmail }}</a></template><template v-else>{{ t('用「邮箱验证码」登录已有账户') }}</template></p>
        <p v-if="featureGuardians" class="auth-footer__note">{{ t('未满你所在地区的儿童年龄，或还在家庭里？登录后到') }}<a href="/family">{{ t('家庭') }}</a>{{ t('页面查看监护人与独立条件。') }}</p>
      </div>
    </div>
</template>
