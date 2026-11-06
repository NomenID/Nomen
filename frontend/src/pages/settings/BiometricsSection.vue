<script setup>
import { onMounted, ref } from 'vue'
import { bytesToBase64url, base64urlToBytes } from '../../lib/webauthn.js'
import { toast } from '../../lib/toast.js'
import { useT } from '../../composables/useT.js'

const { t } = useT()

const props = defineProps({
  csrf: { type: String, default: '' },
  urls: { type: Object, default: () => ({}) },
})

const urls = {
  list: props.urls?.list || '/settings/biometrics',
  remove: props.urls?.remove || '/settings/biometrics/delete',
  options: props.urls?.options || '/biometric/registration/options',
  verify: props.urls?.verify || '/biometric/registration/verify',
}

const supported = 'credentials' in navigator
const list = ref([])
const loading = ref(true)
const formOpen = ref(false)
const registering = ref(false)
const registerText = ref(t('开始绑定'))
const status = ref('')

function deviceTypeText(type) {
  return {
    platform: t('内置传感器（指纹/面容）'),
    usb_key: t('USB 安全密钥'),
    nfc_card: t('NFC 卡片'),
    ble_device: t('BLE 设备'),
  }[type] || t('未知设备')
}

function formatTime(ts) {
  if (!ts) return t('未知')
  const date = new Date(String(ts).replace(' ', 'T') + 'Z')
  return date.toLocaleString(window.NOMEN_LANG || 'zh-Hans')
}

async function loadList() {
  loading.value = true
  try {
    const response = await fetch(urls.list, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    const data = await response.json()
    if (data.success) list.value = data.credentials || []
  } catch (err) {
    // 保持空列表
  } finally {
    loading.value = false
  }
}

onMounted(loadList)

function openForm() {
  formOpen.value = true
  status.value = ''
}

function cancelForm() {
  formOpen.value = false
  status.value = ''
}

async function removeItem(item) {
  if (!window.Nomen || typeof window.Nomen.confirm !== 'function') return

  const confirmed = await window.Nomen.confirm(t('删除生物识别'), t('确定要删除此生物识别凭证吗？删除后需要重新绑定才能使用生物识别登录。'), {
    danger: true,
    confirmText: t('删除'),
    cancelText: t('取消'),
  })
  if (!confirmed) return

  const body = new URLSearchParams()
  body.set('_csrf', props.csrf)
  body.set('id', item.id)
  try {
    const response = await fetch(urls.remove, {
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
      loadList()
    } else {
      toast(data.message, 'error')
    }
  } catch (err) {
    toast(t('网络错误'), 'error')
  }
}

async function register() {
  if (registering.value) return
  registering.value = true
  registerText.value = t('准备中...')
  status.value = t('正在准备绑定...')

  try {
    const optionsResponse = await fetch(urls.options, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    const data = await optionsResponse.json()
    if (!data.success) {
      toast(data.message || t('获取选项失败'), 'error')
      registerText.value = t('开始绑定')
      status.value = ''
      registering.value = false
      return
    }

    const options = data.options
    options.publicKey.challenge = base64urlToBytes(options.publicKey.challenge)
    options.publicKey.user.id = base64urlToBytes(options.publicKey.user.id)

    registerText.value = t('请完成生物识别验证...')
    status.value = t('正在调用系统生物识别（指纹/面容/PIN）...')

    const credential = await navigator.credentials.create({ publicKey: options.publicKey })
    if (!credential) {
      toast(t('绑定取消'), 'warning')
      registerText.value = t('开始绑定')
      status.value = ''
      registering.value = false
      return
    }

    const response = credential.response
    const verifyResponse = await fetch(urls.verify, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({
        credential: {
          id: credential.id,
          type: credential.type,
          rawId: bytesToBase64url(credential.rawId),
          attestationObject: bytesToBase64url(response.attestationObject),
          clientDataJSON: bytesToBase64url(response.clientDataJSON),
          transports: response.getTransports ? response.getTransports() : [],
        },
      }),
    })
    const result = await verifyResponse.json()
    if (result.success) {
      toast(result.message, 'success')
      formOpen.value = false
      loadList()
    } else {
      toast(result.message || t('绑定失败'), 'error')
    }
  } catch (err) {
    toast(t('绑定失败: {:error}', { error: err.message || t('未知错误') }), 'error')
  } finally {
    registering.value = false
    registerText.value = t('开始绑定')
    status.value = ''
  }
}
</script>

<template>
  <div style="margin-bottom:20px;">
    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;">
      <p v-if="loading" class="text-muted" style="padding:12px 0;">{{ t('加载中...') }}</p>
      <p v-else-if="!list.length" class="text-muted" style="padding:12px 0;">{{ t('暂无生物识别凭证') }}</p>
      <div v-for="item in list" :key="item.id" class="device-item">
        <div class="device-item__info">
          <div class="device-item__name">{{ deviceTypeText(item.device_type) }}</div>
          <div class="device-item__meta">{{ t('创建时间: {:time}', { time: formatTime(item.created_at) }) }}</div>
        </div>
        <button type="button" class="btn btn-sm btn-danger-outline" @click="removeItem(item)">{{ t('删除') }}</button>
      </div>
    </div>
    <button v-if="supported" type="button" class="btn btn-secondary" @click="openForm">{{ t('添加生物识别') }}</button>
  </div>

  <div v-show="formOpen">
    <div class="form-group">
      <label>{{ t('绑定生物识别') }}</label>
      <p class="form-hint">{{ t('点击下方按钮后，系统将引导您完成设备生物识别验证（如指纹、面容等）') }}</p>
    </div>
    <div style="display:flex;gap:8px;">
      <button type="button" class="btn btn-primary" :disabled="registering" @click="register">{{ registerText }}</button>
      <button type="button" class="btn btn-secondary" :disabled="registering" @click="cancelForm">{{ t('取消') }}</button>
    </div>
    <div style="margin-top:12px;font-size:13px;color:var(--color-text-subtle);">{{ status }}</div>
  </div>
</template>
