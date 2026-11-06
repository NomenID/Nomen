<script setup>
import { computed, onMounted, ref } from 'vue'
import { toast } from '../../lib/toast.js'
import { useT } from '../../composables/useT.js'

const { t } = useT()

const props = defineProps({
  csrf: { type: String, default: '' },
  keys: { type: Array, default: () => [] },
  urls: { type: Object, default: () => ({}) },
})

const urls = {
  list: props.urls?.list || '/settings/temp-keys',
  create: props.urls?.create || '/settings/temp-keys',
  remove: props.urls?.remove || '/settings/temp-keys/delete',
  generate: props.urls?.generate || '/settings/temp-keys/generate',
}

const keys = ref(props.keys.map(normalizeKey))
const formOpen = ref(false)
const label = ref('')
const value = ref('')
const valueReadonly = ref(false)
const expires = ref('')
const saving = ref(false)

function normalizeKey(key) {
  return {
    id: Number(key.id),
    label: String(key.label || ''),
    expires_at: String(key.expires_at || ''),
    created_at: String(key.created_at || ''),
    last_used_at: key.last_used_at ? String(key.last_used_at) : '',
    expired: new Date(String(key.expires_at).replace(' ', 'T') + 'Z') < new Date(),
  }
}

function formatTime(ts) {
  if (!ts) return t('从未使用')
  const date = new Date(String(ts).replace(' ', 'T') + 'Z')
  return date.toLocaleString(window.NOMEN_LANG || 'zh-Hans')
}

async function loadKeys() {
  try {
    const response = await fetch(urls.list, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    const data = await response.json()
    if (data.success) keys.value = (data.keys || []).map(normalizeKey)
  } catch (err) {
    // 保持当前列表
  }
}

onMounted(loadKeys)

async function generateKey() {
  try {
    const response = await fetch(urls.generate, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    const data = await response.json()
    if (data.success) value.value = data.key
  } catch (err) {
    // 生成失败就让它手填
  }
}

async function openForm() {
  formOpen.value = true
  label.value = ''
  value.value = ''
  valueReadonly.value = false
  await generateKey()

  const now = new Date()
  now.setHours(now.getHours() + 12)
  expires.value = now.toISOString().slice(0, 19)
}

function cancelForm() {
  formOpen.value = false
}

async function save() {
  if (!label.value.trim() || !value.value.trim() || !expires.value) {
    toast(t('请填写所有字段'), 'warning')
    return
  }

  saving.value = true
  const body = new URLSearchParams()
  body.set('_csrf', props.csrf)
  body.set('label', label.value.trim())
  body.set('key_value', value.value.trim())
  body.set('expires_at', expires.value.slice(0, 16).replace('T', ' '))

  try {
    const response = await fetch(urls.create, {
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
      formOpen.value = false
      loadKeys()
    } else {
      toast(data.message, 'error')
    }
  } catch (err) {
    toast(t('网络错误'), 'error')
  } finally {
    saving.value = false
  }
}

async function removeKey(item) {
  if (!window.Nomen || typeof window.Nomen.confirm !== 'function') return

  const confirmed = await window.Nomen.confirm(t('删除临时密钥'), t('确定要删除此临时密钥吗？'), {
    danger: true,
    confirmText: t('删除'),
    cancelText: t('取消'),
  })
  if (!confirmed) return

  const body = new URLSearchParams()
  body.set('_csrf', props.csrf)
  body.set('key_id', item.id)
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
      loadKeys()
    } else {
      toast(data.message, 'error')
    }
  } catch (err) {
    toast(t('网络错误'), 'error')
  }
}
</script>

<template>
  <div style="margin-bottom:20px;">
    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;">
      <p v-if="!keys.length" class="text-muted" style="padding:12px 0;">{{ t('暂无临时密钥') }}</p>
      <div v-for="item in keys" :key="item.id" class="device-item">
        <div class="device-item__info">
          <div class="device-item__name">
            <span :style="item.expired ? 'text-decoration:line-through;' : ''">{{ item.label || t('未命名密钥') }}</span>
            <span v-if="item.expired" class="badge" style="margin-left:8px;font-size:11px;background:#eee;color:#999;">{{ t('已过期') }}</span>
          </div>
          <div class="device-item__meta">
            {{ t('到期: {:expires} · 创建: {:created}', { expires: formatTime(item.expires_at), created: formatTime(item.created_at) }) }}{{ item.last_used_at ? t(' · 上次使用: {:used}', { used: formatTime(item.last_used_at) }) : '' }}
          </div>
        </div>
        <button type="button" class="btn btn-sm btn-danger-outline" @click="removeKey(item)">{{ t('删除') }}</button>
      </div>
    </div>
    <button v-show="!formOpen" type="button" class="btn btn-secondary" @click="openForm">{{ t('创建临时密钥') }}</button>
  </div>

  <div v-show="formOpen">
    <div class="form-group">
      <label for="temp-key-label">{{ t('密钥名称') }}</label>
      <input id="temp-key-label" v-model="label" type="text" :placeholder="t('例如：学校电脑')" maxlength="50">
    </div>
    <div class="form-group">
      <label for="temp-key-value">{{ t('密钥值') }}</label>
      <div style="display:flex;gap:8px;">
        <input id="temp-key-value" v-model="value" type="text" :placeholder="t('自动生成或自定义')" maxlength="64">
        <button type="button" class="btn btn-sm btn-secondary" @click="generateKey">{{ t('生成') }}</button>
      </div>
      <small class="form-hint">{{ t('至少10位，必须包含字母和数字，默认生成16位随机密钥') }}</small>
    </div>
    <div class="form-group">
      <label for="temp-key-expires">{{ t('到期时间') }}</label>
      <input id="temp-key-expires" v-model="expires" type="datetime-local" step="1">
      <small class="form-hint">{{ t('最长有效期14天') }}</small>
    </div>
    <div style="display:flex;gap:8px;">
      <button type="button" class="btn btn-primary" :disabled="saving" @click="save">{{ saving ? t('保存中...') : t('保存') }}</button>
      <button type="button" class="btn btn-secondary" @click="cancelForm">{{ t('取消') }}</button>
    </div>
  </div>
</template>
