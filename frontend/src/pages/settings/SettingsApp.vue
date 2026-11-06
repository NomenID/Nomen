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
const quickLogin = ref(props.user.quickLogin ? '1' : '0')
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
    toast(t('SVG 已转成 {:n}×{:n} 的 PNG', { n: SVG_PNG_SIZE }), 'success')
  } catch (err) {
    if (avatarInput.value) avatarInput.value.value = ''
    toast(t('这个 SVG 转不出来，请换成 PNG 或 JPG'), 'error')
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
    toast(t('网络错误'), 'error')
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
    toast(result.message || (result.success ? t('已保存') : t('保存失败')), result.success ? 'success' : 'error')
  } catch (err) {
    toast(err.message || t('网络错误'), 'error')
  } finally {
    profileSaving.value = false
  }
}

// ---- 密码表单 ----
const passwordSaving = ref(false)
const passwordStrengthInfo = computed(() => passwordStrength(passwordForm.next))
const passwordStrengthText = computed(() => (passwordForm.next ? t('密码强度：{:label}', { label: t(passwordStrengthInfo.value.label) }) : t('密码强度')))

async function generatePassword() {
  const password = generateStrongPassword(16)
  passwordForm.next = password
  passwordForm.confirm = password
  const ok = await copyText(password)
  toast(
    ok
      ? t('已生成强密码并复制。粘贴到密码管理器后，请清空剪贴板。')
      : t('已生成强密码。请在密码框中全选复制，并注意清空剪贴板。'),
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
    toast(result.message || (result.success ? t('已保存') : t('保存失败')), result.success ? 'success' : 'error')
    if (result.success) {
      passwordForm.current = ''
      passwordForm.next = ''
      passwordForm.confirm = ''
    }
  } catch (err) {
    toast(err.message || t('网络错误'), 'error')
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
    toast(t('网络错误'), 'error')
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
    toast(t('请填写所有字段'), 'warning')
    return
  }
  if (resetForm.next.length < 8 || !/[a-zA-Z]/.test(resetForm.next) || !/[0-9]/.test(resetForm.next)) {
    toast(t('密码至少8位，含字母和数字'), 'warning')
    return
  }
  if (resetForm.next !== resetForm.confirm) {
    toast(t('两次密码不一致'), 'warning')
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
      toast(result.message || t('重置失败'), 'error')
    }
  } catch (err) {
    toast(t('网络错误'), 'error')
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
    toast(result.message || (result.success ? t('已保存') : t('保存失败')), result.success ? 'success' : 'error')
  } catch (err) {
    toast(t('网络错误'), 'error')
  } finally {
    policySaving.value = false
  }
}

const quickSaving = ref(false)

async function saveQuickLogin() {
  if (quickSaving.value) return
  quickSaving.value = true
  try {
    const result = await postForm('/settings/quick-login', { quick_login: quickLogin.value })
    toast(result.message || (result.success ? t('已保存') : t('保存失败')), result.success ? 'success' : 'error')
  } catch (err) {
    toast(t('网络错误'), 'error')
  } finally {
    quickSaving.value = false
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
    toast(result.message || (result.success ? t('已保存') : t('保存失败')), result.success ? 'success' : 'error')
  } catch (err) {
    toast(t('网络错误'), 'error')
  } finally {
    notifSaving.value = false
  }
}

// ---- 撤销信任设备 ----
async function revokeDevice(device) {
  if (!window.Nomen || typeof window.Nomen.confirm !== 'function') return

  const confirmed = await window.Nomen.confirm(t('撤销设备信任'), t('确定要撤销设备 "{:name}" 的信任吗？该设备下次登录需要重新验证。', { name: device.deviceName }), {
    danger: true,
    confirmText: t('撤销'),
    cancelText: t('取消'),
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
    toast(t('网络错误'), 'error')
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
    <h1>{{ t('账户设置') }}</h1>

    <BaseAlert v-if="user.error" type="error" :message="user.error" />
    <BaseAlert v-if="user.success" type="success" :message="user.success" />

    <div class="settings-section" id="sec-avatar">
      <h2>{{ t('头像') }}</h2>
      <div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;">
        <div
          style="width:80px;height:80px;border-radius:50%;overflow:hidden;flex-shrink:0;background:#e9ecef;display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:600;color:#6c757d;"
        >
          <img v-if="avatarUrl" :src="avatarUrl" :alt="t('头像')" style="width:100%;height:100%;object-fit:cover;">
          <template v-else>{{ userInitial }}</template>
        </div>
        <div>
          <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <input ref="avatarInput" type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml" style="display:none;" @change="onAvatarChange">
            <button type="button" class="btn btn-secondary" @click="avatarInput?.click()">{{ t('选择图片') }}</button>
            <button v-show="avatarFile" type="button" class="btn btn-primary" :disabled="avatarUploading" @click="uploadAvatar">{{ avatarUploading ? t('上传中...') : t('上传头像') }}</button>
            <span class="text-muted" style="font-size:13px;">{{ avatarFileName }}</span>
          </div>
          <small class="form-hint" style="margin-top:8px;">{{ t('支持 JPG、PNG、GIF、WebP、SVG，最大 2MB；SVG 会自动转成 135×135 PNG。头像将裁剪为 135×135 正方形') }}</small>
        </div>
      </div>
    </div>

    <div class="settings-section" id="sec-profile">
      <h2>{{ t('个人资料') }}</h2>
      <form class="settings-form" @submit.prevent="saveProfile">
        <div class="form-group">
          <label>{{ t('用户名') }}</label>
          <input type="text" :value="user.username" disabled class="input-disabled">
          <small class="form-hint">{{ t('用户名无法修改') }}</small>
        </div>
        <div class="form-group">
          <label>{{ t('首选邮箱') }}</label>
          <template v-if="user.email">
            <input type="text" :value="user.email" disabled class="input-disabled">
            <small class="form-hint">{{ t('发通知、找回账号都用它；要换在下面「邮箱地址」里设') }}</small>
          </template>
          <template v-else>
            <input type="text" :value="features.guardians ? t('未设置（收信走家长邮箱）') : t('未设置')" disabled class="input-disabled">
            <small v-if="features.guardians" class="form-hint">{{ t('在下面「邮箱地址」里可以绑定一个属于你自己的邮箱') }}</small>
          </template>
        </div>
        <div class="form-group">
          <label>{{ t('出生日期') }}</label>
          <input type="text" :value="user.birthDateDisplay" disabled class="input-disabled">
        </div>
        <div class="form-group">
          <label>{{ t('性别') }}</label>
          <input type="text" :value="user.genderDisplay" disabled class="input-disabled">
        </div>
        <div v-if="features.country" class="form-group">
          <label>{{ t('国家 / 地区') }}</label>
          <input type="text" :value="user.countryDisplay" disabled class="input-disabled">
        </div>
        <div class="form-group">
          <label for="display_name">{{ t('显示名称') }}</label>
          <input id="display_name" v-model="profile.display_name" type="text" :placeholder="t('您希望显示的名称')">
        </div>
        <div class="form-group">
          <label for="bio">{{ t('个人简介') }}</label>
          <textarea id="bio" v-model="profile.bio" rows="3" :placeholder="t('介绍一下自己')"></textarea>
        </div>
        <button type="submit" class="btn btn-primary" :disabled="profileSaving">{{ profileSaving ? t('保存中...') : t('保存修改') }}</button>
      </form>
    </div>

    <div class="settings-section" id="sec-password">
      <h2>{{ t('修改密码') }}</h2>
      <form class="settings-form" @submit.prevent="savePassword">
        <div class="form-group">
          <label for="current_password">{{ t('当前密码') }}</label>
          <input id="current_password" v-model="passwordForm.current" type="password" required>
        </div>
        <div class="form-group">
          <label for="new_password">{{ t('新密码') }}</label>
          <div class="pw-wrap">
            <input id="new_password" v-model="passwordForm.next" type="password" required minlength="8" :placeholder="t('至少8位，包含字母和数字')">
          </div>
          <div class="pw-strength">
            <div class="pw-strength__bar">
              <div class="pw-strength__fill" :style="{ width: passwordStrengthInfo.width, background: passwordStrengthInfo.color }"></div>
            </div>
            <span class="pw-strength__text" :style="{ color: passwordStrengthInfo.color }">{{ passwordStrengthText }}</span>
            <button type="button" class="pw-gen" :title="t('生成一个随机强密码')" @mousedown.prevent @click="generatePassword">{{ t('生成') }}</button>
          </div>
        </div>
        <div class="form-group">
          <label for="confirm_password">{{ t('确认新密码') }}</label>
          <input id="confirm_password" v-model="passwordForm.confirm" type="password" required>
        </div>
        <button type="submit" class="btn btn-primary" :disabled="passwordSaving">{{ passwordSaving ? t('保存中...') : t('修改密码') }}</button>
      </form>
    </div>

    <div class="settings-section" id="sec-reset-code">
      <h2>{{ t('通过邮箱验证码重置密码') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('如果不记得当前密码，可以通过邮箱验证码重置密码') }}</p>
      <form class="settings-form" @submit.prevent="submitReset">
        <div v-if="verifiedEmails.length > 1" class="form-group">
          <label for="reset_code_target">{{ t('收码邮箱') }}</label>
          <select id="reset_code_target" v-model="resetForm.target" class="email-target-select">
            <option v-for="choice in verifiedEmails" :key="choice.email" :value="choice.email">{{ choice.email }}{{ choice.is_primary ? t('（首选）') : '' }}</option>
          </select>
        </div>
        <div class="form-group">
          <label>{{ t('验证码') }}</label>
          <div class="input-with-button">
            <input id="reset_code" v-model="resetForm.code" type="text" name="reset_code" required :placeholder="t('请输入6位验证码')" maxlength="6">
            <button type="button" id="send-reset-code-btn" class="btn btn-secondary" :disabled="resetSending || resetCooldown > 0" @click="sendResetCode">
              {{ resetSending ? t('发送中...') : resetCooldown > 0 ? t('{:n}秒后重试', { n: resetCooldown }) : t('发送验证码') }}
            </button>
          </div>
        </div>
        <div class="form-group">
          <label for="reset_new_password">{{ t('新密码') }}</label>
          <div class="pw-wrap">
            <input id="reset_new_password" v-model="resetForm.next" type="password" required minlength="8" :placeholder="t('至少8位，包含字母和数字')">
          </div>
        </div>
        <div class="form-group">
          <label for="reset_confirm_password">{{ t('确认新密码') }}</label>
          <input id="reset_confirm_password" v-model="resetForm.confirm" type="password" required>
        </div>
        <button type="submit" id="reset-btn" class="btn btn-primary" :disabled="resetSaving">{{ resetSaving ? t('重置中...') : t('重置密码') }}</button>
      </form>
    </div>

    <div class="settings-section" id="sec-emails">
      <h2>{{ t('邮箱地址') }}</h2>
      <p v-if="!user.email && features.guardians" class="form-hint" style="margin-bottom:16px;">
        {{ t('你现在的收信地址是家长的邮箱。绑定一个属于你自己的邮箱后，通知会发到这里；家里的事（家长、停止监护）都在') }}<a href="/family">{{ t('家庭') }}</a>{{ t('页面。') }}
      </p>
      <p class="form-hint" style="margin-bottom:16px;">
        {{ t('最多可以绑定 {:n} 个邮箱。发通知、找回账号用的是「首选」那一个；登录时任何一个已验证的邮箱都能用。', { n: maxEmails }) }}
      </p>
      <EmailSetSection :csrf="csrf" :emails="emails" :max-emails="maxEmails" />
    </div>

    <div class="settings-section settings-section--danger" id="sec-account">
      <h2>{{ t('账户信息') }}</h2>
      <div class="info-grid">
        <div class="info-item">
          <span class="info-label">{{ t('注册时间') }}</span>
          <span class="info-value">{{ user.createdAtDisplay }}</span>
        </div>
        <div class="info-item">
          <span class="info-label">{{ t('收信地址') }}</span>
          <span class="info-value">
            <span v-if="user.contactVerified" class="badge badge--success">{{ t('已验证') }}</span>
            <span v-else class="badge badge--warning">{{ t('未验证') }}</span>
          </span>
        </div>
      </div>
    </div>

    <div class="settings-section" id="sec-active-devices">
      <h2>{{ t('活跃设备') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('近30天活跃的登录设备，可查看但无法撤销（撤销在下方所有设备中操作）') }}</p>
      <p v-if="!activeTokens.length" class="text-muted" style="padding:12px 0;">{{ t('暂无活跃设备') }}</p>
      <div v-else class="token-list">
        <div v-for="token in activeTokens" :key="token.deviceId" class="token-item">
          <div class="token-item__info">
            <div class="token-item__name">
              {{ token.deviceName }}
              <span v-if="token.isCurrent" class="badge badge--info" style="margin-left:8px;font-size:11px;">{{ t('当前设备') }}</span>
            </div>
            <div class="token-item__meta">{{ token.meta }}</div>
          </div>
          <span class="badge badge--success">{{ t('活跃') }}</span>
        </div>
      </div>
    </div>

    <div class="settings-section" id="sec-temp-keys">
      <h2>{{ t('临时密钥') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('临时密钥可用于在不信任的设备上登录（如学校电脑），登录时跳过设备验证。最长有效期14天，最多同时存在5个') }}</p>
      <TempKeysSection :csrf="csrf" :urls="urls.tempKeys" />
    </div>

    <div class="settings-section" id="sec-2fa">
      <h2>{{ t('两步验证') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('选择登录时何时要求第二步验证。默认只在未信任的新设备上验证') }}</p>
      <form @submit.prevent="savePolicy">
        <div class="factor-select" style="margin-bottom:16px;">
          <label class="factor-option" :class="{ selected: policy === 'new_device' }">
            <input v-model="policy" type="radio" name="policy" value="new_device">
            <span class="factor-icon"><Icon name="monitor" /></span>
            <span class="factor-label">{{ t('仅新设备验证（推荐）') }}</span>
          </label>
          <label class="factor-option" :class="{ selected: policy === 'every_login' }">
            <input v-model="policy" type="radio" name="policy" value="every_login">
            <span class="factor-icon"><Icon name="lock" /></span>
            <span class="factor-label">{{ t('每次登录都验证') }}</span>
          </label>
        </div>
        <p class="form-hint" style="margin-bottom:12px;">{{ t('开启「每次登录都验证」前，需先验证邮箱或启用验证器 APP') }}</p>
        <button type="submit" class="btn btn-primary" :disabled="policySaving">{{ policySaving ? t('保存中...') : t('保存策略') }}</button>
      </form>
    </div>

    <div class="settings-section" id="sec-locale" v-if="locales.length">
      <h2>{{ t('界面语言') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('选择 Nomen 界面与系统邮件使用的语言。未登录的设备会跟随浏览器语言') }}</p>
      <form method="POST" action="/settings/locale">
        <input type="hidden" name="_csrf" :value="csrf">
        <div class="form-group" style="margin-bottom:16px;">
          <label for="locale-select">{{ t('语言') }}</label>
          <select id="locale-select" name="locale">
            <option v-for="entry in locales" :key="entry.slug" :value="entry.slug" :selected="entry.slug === currentLocale">
              {{ entry.name }} ({{ entry.english }})
            </option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary">{{ t('保存') }}</button>
      </form>
    </div>

    <div class="settings-section" id="sec-skin">
      <h2>{{ t('外观') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('选择站点外观。没登录的设备用站点默认皮肤') }}</p>
      <form method="POST" action="/settings/skin">
        <input type="hidden" name="_csrf" :value="csrf">
        <div class="form-group" style="margin-bottom:16px;">
          <label for="skin-select">{{ t('皮肤') }}</label>
          <select id="skin-select" name="skin">
            <option v-for="entry in skins" :key="entry.slug" :value="entry.slug" :selected="entry.slug === currentSkin">
              {{ entry.name }}
            </option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary">{{ t('保存') }}</button>
      </form>
    </div>

    <div class="settings-section" id="sec-quick-login">
      <h2>{{ t('Nomen启航计划项目快速中央登录') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('开启后，本站自家应用登录时不再逐项确认权限，直接授权并在回调地址带上快速登录标记。关闭后所有应用都走普通授权确认流程') }}</p>
      <form @submit.prevent="saveQuickLogin">
        <div class="factor-select" style="margin-bottom:16px;">
          <label class="factor-option" :class="{ selected: quickLogin === '1' }">
            <input v-model="quickLogin" type="radio" name="quick_login" value="1">
            <span class="factor-icon"><Icon name="zap" /></span>
            <span class="factor-label">{{ t('开启（默认）') }}</span>
          </label>
          <label class="factor-option" :class="{ selected: quickLogin === '0' }">
            <input v-model="quickLogin" type="radio" name="quick_login" value="0">
            <span class="factor-icon"><Icon name="shield-check" /></span>
            <span class="factor-label">{{ t('关闭，逐项确认权限') }}</span>
          </label>
        </div>
        <button type="submit" class="btn btn-primary" :disabled="quickSaving">{{ quickSaving ? t('保存中...') : t('保存') }}</button>
      </form>
    </div>

    <div class="settings-section" id="sec-notifications">
      <h2>{{ t('邮件通知偏好') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('勾选的类别以后会通过邮件推送到你的邮箱；站内消息（右上角铃铛）始终接收，不受这里的开关影响') }}</p>
      <form @submit.prevent="saveNotif">
        <label class="notif-pref-item">
          <input v-model="notif.system_important" type="checkbox" value="1">
          <span class="notif-pref-item__text">
            <strong>{{ t('系统重要通知') }}</strong>
            <span class="form-hint">{{ t('安全事件等需要你立即注意的事项（建议保持开启）') }}</span>
          </span>
        </label>
        <label class="notif-pref-item">
          <input v-model="notif.system_general" type="checkbox" value="1">
          <span class="notif-pref-item__text">
            <strong>{{ t('系统一般通知') }}</strong>
            <span class="form-hint">{{ t('账号安全建议、功能更新等一般性通知') }}</span>
          </span>
        </label>
        <label class="notif-pref-item">
          <input v-model="notif.system_interact" type="checkbox" value="1">
          <span class="notif-pref-item__text">
            <strong>{{ t('系统互动') }}</strong>
            <span class="form-hint">{{ t('与你的账号相关的互动提醒') }}</span>
          </span>
        </label>
        <button type="submit" class="btn btn-primary" style="margin-top:12px;" :disabled="notifSaving">{{ notifSaving ? t('保存中...') : t('保存') }}</button>
      </form>
    </div>

    <div class="settings-section" id="sec-totp">
      <h2>{{ t('验证器 APP（TOTP）') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('使用 Google Authenticator、Authy 等 TOTP 应用生成6位动态验证码，作为双因素验证的第二要素') }}</p>
      <TotpSection :csrf="csrf" />
    </div>

    <div class="settings-section" id="sec-recovery">
      <h2>{{ t('恢复码') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('恢复码是最后手段的验证方式：无法使用验证器 APP 或邮箱验证码时，可以用它完成登录验证，每个恢复码只能使用一次。恢复码可以独立使用 —— 即使不启用验证器 APP（TOTP），生成后登录二验时也能选择「恢复码」。生成后请立即打印或抄写到纸上，保存在安全的地方。') }}</p>
      <RecoveryCodesSection :csrf="csrf" />
    </div>

    <div class="settings-section" id="sec-biometric">
      <h2>{{ t('生物识别') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('绑定生物识别（如 Windows Hello、指纹、面容 ID）后可快速登录，属于{:label}、不计入登录方式数量。最多同时绑定5个设备', { label: user.assistLabel }) }}</p>
      <BiometricsSection :csrf="csrf" />
    </div>

    <div class="settings-section" id="sec-devices">
      <h2>{{ t('所有设备') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('已信任的设备在登录时无需额外验证。撤销设备信任后，该设备下次登录需要重新验证') }}</p>
      <p v-if="!devices.length" class="text-muted" style="padding:12px 0;">{{ t('暂无信任设备') }}</p>
      <div v-else class="device-list" id="device-list">
        <div v-for="device in devices" :key="device.deviceId" class="device-item">
          <div class="device-item__info">
            <div class="device-item__name">
              {{ device.deviceName }}
              <span v-if="device.isCurrent" class="badge badge--info" style="margin-left:8px;font-size:11px;">{{ t('当前设备') }}</span>
            </div>
            <div class="device-item__meta">{{ device.meta }}</div>
          </div>
          <button v-if="!device.isCurrent" type="button" class="btn btn-sm btn-danger-outline" @click="revokeDevice(device)">{{ t('撤销') }}</button>
        </div>
      </div>
    </div>

    <div v-if="features.guardians" class="settings-section" id="sec-family">
      <h2>{{ t('家庭') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">
        {{ t('家长加入你的家庭后，可以帮你重置密码、临时锁定账号；你也可以在这里邀请家长，或者（满本地区年龄后）停止监护、退出家庭。') }}
      </p>
      <a href="/family" class="btn btn-secondary">{{ t('打开家庭') }}</a>
    </div>
  </div>
</template>
