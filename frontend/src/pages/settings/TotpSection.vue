<script setup>
import { onMounted, ref } from 'vue'
import Icon from '../../components/Icon.vue'
import { toast } from '../../lib/toast.js'
import { useT } from '../../composables/useT.js'

const { t } = useT()

const props = defineProps({
  csrf: { type: String, default: '' },
  recoveryMode: { type: Boolean, default: false },
  labelEnable: { type: String, default: '启用 TOTP' },
  labelDisable: { type: String, default: '禁用 TOTP' },
  labelEnabled: { type: String, default: 'TOTP 已启用' },
  labelNotEnabled: { type: String, default: '您尚未启用 TOTP' },
  labelVerify: { type: String, default: '验证并启用' },
  urls: { type: Object, default: () => ({}) },
})

const urls = {
  status: props.urls?.status || '/2fa/totp/status',
  setup: props.urls?.setup || '/2fa/totp/setup',
  verify: props.urls?.verify || '/2fa/totp/verify',
  disable: props.urls?.disable || '/2fa/totp/disable',
}

const enabled = ref(false)
const setupOpen = ref(false)
const setupLoading = ref(false)
const verifying = ref(false)
const secret = ref('')
const qrUrl = ref('')
const qrFailed = ref(false)
const code = ref('')
const disableBtn = ref(null)

async function loadStatus() {
  try {
    const response = await fetch(urls.status, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    const data = await response.json()
    if (data.success) enabled.value = !!data.enabled
  } catch (err) {
    // 状态拿不到就保持当前显示，不打扰
  }
}

onMounted(loadStatus)

async function startSetup() {
  setupLoading.value = true
  try {
    const response = await fetch(urls.setup, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    const data = await response.json()
    if (!data.success) {
      toast(t('加载失败'), 'error')
      return
    }
    if (data.has_totp) {
      toast(t('TOTP 已启用'), 'info')
      loadStatus()
      return
    }
    setupOpen.value = true
    secret.value = data.secret || ''
    qrFailed.value = false
    qrUrl.value = data.uri
      ? 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + encodeURIComponent(data.uri)
      : ''
  } catch (err) {
    toast(t('网络错误'), 'error')
  } finally {
    setupLoading.value = false
  }
}

function cancelSetup() {
  setupOpen.value = false
  code.value = ''
}

async function verifySetup() {
  const value = code.value.trim()
  if (!value || value.length !== 6) {
    toast(t('请输入6位验证码'), 'warning')
    return
  }

  verifying.value = true
  try {
    const body = new URLSearchParams()
    body.set('_csrf', props.csrf)
    body.set('code', value)
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
      toast(t('TOTP 已启用'), 'success')
      setupOpen.value = false
      code.value = ''
      enabled.value = true
      // TOTP 启用时自动生成的恢复码在这里补展示，用户必须当场保存
      if (data.recovery_codes && data.recovery_codes.length) {
        window.dispatchEvent(new CustomEvent('nomen:recovery-codes', { detail: data.recovery_codes }))
      }
    } else {
      toast(data.message, 'error')
    }
  } catch (err) {
    toast(t('请求失败'), 'error')
  } finally {
    verifying.value = false
  }
}

function disableTotp() {
  if (!window.Nomen || typeof window.Nomen.prompt !== 'function') return

  window.Nomen.prompt(t('禁用 TOTP'), props.recoveryMode
    ? t('恢复流程中无需填写密码，直接确认即可：')
    : t('请输入当前密码以确认禁用 TOTP 验证器：'), {
    confirmText: t('确认禁用'),
    cancelText: t('取消'),
    danger: true,
    inputType: 'password',
    placeholder: props.recoveryMode ? t('恢复流程中可留空') : t('当前密码'),
    required: !props.recoveryMode,
  }).then(async (password) => {
    if (password === null) return

    const body = new URLSearchParams()
    body.set('_csrf', props.csrf)
    body.set('password', password)
    try {
      const response = await fetch(urls.disable, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: body.toString(),
      })
      const data = await response.json()
      if (data.success) {
        toast(t('TOTP 已禁用'), 'success')
        enabled.value = false
        setupOpen.value = false
      } else {
        toast(data.message, 'error')
      }
    } catch (err) {
      toast(t('网络错误'), 'error')
    }
  })
}
</script>

<template>
  <div>
    <div v-if="!enabled">
      <p class="text-muted" style="margin-bottom:16px;">{{ t(labelNotEnabled) }}</p>
      <button type="button" class="btn btn-primary" :disabled="setupLoading" @click="startSetup">{{ t(labelEnable) }}</button>
    </div>
    <div v-else>
      <p style="margin-bottom:12px;"><Icon name="check" extra="icon--success" /> <span style="color:var(--color-success);font-weight:600;">{{ t(labelEnabled) }}</span></p>
      <button ref="disableBtn" type="button" class="btn btn-sm btn-danger-outline" @click="disableTotp">{{ t(labelDisable) }}</button>
    </div>

    <div v-show="setupOpen" style="margin-top:16px;">
      <div class="form-group">
        <label>{{ t('1. 在验证器应用中添加账户') }}</label>
        <p class="form-hint">{{ t('手动输入密钥或使用应用扫描二维码：') }}</p>
        <div style="text-align:center;margin:16px 0;">
          <div
            style="width:180px;height:180px;margin:0 auto;background:var(--color-bg-tertiary);border-radius:12px;display:flex;align-items:center;justify-content:center;color:var(--color-text-subtle);font-size:13px;"
          >
            <img
              v-if="qrUrl && !qrFailed"
              :src="qrUrl"
              :alt="t('TOTP 二维码')"
              style="width:180px;height:180px;border-radius:8px;display:block;"
              @error="qrFailed = true"
            >
            <span v-else-if="qrFailed">{{ t('无法加载二维码，请手动输入下方密钥') }}</span>
            <span v-else>{{ t('加载中...') }}</span>
          </div>
        </div>
        <div style="background:var(--color-bg-tertiary);border-radius:8px;padding:12px;text-align:center;font-family:var(--font-mono);font-size:14px;word-break:break-all;">{{ secret }}</div>
        <p class="form-hint" style="margin-top:8px;text-align:center;">{{ t('密钥区分大小写，请完整输入') }}</p>
      </div>
      <div class="form-group">
        <label>{{ t('2. 输入验证器APP生成的6位验证码') }}</label>
        <div class="input-with-button">
          <input v-model="code" type="text" :placeholder="t('请输入6位验证码')" maxlength="6" autocomplete="off">
          <button type="button" class="btn btn-primary" :disabled="verifying" @click="verifySetup">{{ verifying ? t('验证中...') : t(labelVerify) }}</button>
        </div>
      </div>
      <button type="button" class="btn btn-sm btn-ghost" style="margin-top:8px;" @click="cancelSetup">{{ t('取消') }}</button>
    </div>
  </div>
</template>
