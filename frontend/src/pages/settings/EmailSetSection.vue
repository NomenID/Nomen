<script setup>
import { computed, ref } from 'vue'
import { toast } from '../../lib/toast.js'
import { useT } from '../../composables/useT.js'

const { t } = useT()

const props = defineProps({
  csrf: { type: String, default: '' },
  emails: { type: Array, default: () => [] },
  maxEmails: { type: Number, default: 5 },
  recoveryMode: { type: Boolean, default: false },
  urls: { type: Object, default: () => ({}) },
})

const urls = {
  add: props.urls?.add || '/settings/emails/add',
  resend: props.urls?.resend || '/settings/emails/resend',
  primary: props.urls?.primary || '/settings/emails/primary',
  remove: props.urls?.remove || '/settings/emails/remove',
}

const list = ref(props.emails.map(normalizeRow))
const newEmail = ref('')
const addPassword = ref('')
const adding = ref(false)
const resendingId = ref(0)

const canAdd = computed(() => list.value.length < props.maxEmails)

function normalizeRow(row) {
  return {
    id: Number(row.id),
    email: String(row.email),
    is_primary: !!(row.is_primary ?? row['isPrimary']),
    verified: !!(row.verified ?? row['isVerified']),
  }
}

async function post(url, params) {
  const body = new URLSearchParams()
  body.append('_csrf', props.csrf)
  for (const [key, value] of Object.entries(params)) body.append(key, value)

  const response = await fetch(url, {
    method: 'POST',
    body,
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  })
  try {
    return await response.json()
  } catch (err) {
    throw new Error(t('服务器返回了 {:status}', { status: response.status }))
  }
}

function applyResult(data) {
  toast(data.message, data.success ? 'success' : 'error')
  if (data.success && Array.isArray(data.emails)) {
    list.value = data.emails.map(normalizeRow)
  }
  return !!data.success
}

async function onAdd() {
  const email = newEmail.value.trim()
  if (!email) {
    toast(t('请输入邮箱地址'), 'warning')
    return
  }
  if (!props.recoveryMode && !addPassword.value) {
    toast(t('请输入当前密码'), 'warning')
    return
  }

  adding.value = true
  try {
    const data = await post(urls.add, { new_email: email, password: addPassword.value })
    if (applyResult(data)) {
      newEmail.value = ''
      addPassword.value = ''
    }
  } catch (err) {
    toast(err.message || t('网络错误'), 'error')
  } finally {
    adding.value = false
  }
}

async function onResend(row) {
  if (resendingId.value) return
  resendingId.value = row.id
  try {
    const data = await post(urls.resend, { email_id: row.id })
    applyResult(data)
  } catch (err) {
    toast(err.message || t('网络错误'), 'error')
  } finally {
    resendingId.value = 0
  }
}

function onPrimary(row) {
  promptPassword(t('设为首选'), t('把 {:email} 设为收信地址和登录首选邮箱。', { email: row.email }), false, row.id, urls.primary)
}

function onRemove(row) {
  promptPassword(t('移除邮箱'), t('移除 {:email} 之后，这个地址就不能再用来登录了。', { email: row.email }), true, row.id, urls.remove)
}

function promptPassword(title, message, danger, emailId, url) {
  if (!window.Nomen || typeof window.Nomen.prompt !== 'function') return
  window.Nomen.prompt(title, message, {
    inputType: 'password',
    placeholder: props.recoveryMode ? t('恢复流程中可留空') : t('当前密码'),
    required: !props.recoveryMode,
    confirmText: danger ? t('移除') : t('设为首选'),
    danger,
  }).then(async (password) => {
    if (password === null) return
    try {
      const data = await post(url, { email_id: emailId, password })
      applyResult(data)
    } catch (err) {
      toast(err.message || t('网络错误'), 'error')
    }
  })
}
</script>

<template>
  <div class="email-list" :data-recovery-mode="recoveryMode ? '1' : '0'">
    <div v-for="row in list" :key="row.id" class="email-item" :data-email-id="row.id">
      <div class="email-item__main">
        <span class="email-item__addr">{{ row.email }}</span>
        <span v-if="row.is_primary" class="badge badge--info">{{ t('首选') }}</span>
        <span v-if="row.verified" class="badge badge--success">{{ t('已验证') }}</span>
        <span v-else class="badge badge--warning">{{ t('待验证') }}</span>
      </div>
      <div class="email-item__actions">
        <button
          v-if="!row.verified"
          type="button"
          class="btn btn-sm btn-secondary"
          :disabled="resendingId === row.id"
          @click="onResend(row)"
        >{{ resendingId === row.id ? t('发送中...') : t('重新发送验证邮件') }}</button>
        <button
          v-else-if="!row.is_primary"
          type="button"
          class="btn btn-sm btn-secondary"
          @click="onPrimary(row)"
        >{{ t('设为首选') }}</button>
        <button
          v-if="list.length > 1"
          type="button"
          class="btn btn-sm btn-danger-outline"
          @click="onRemove(row)"
        >{{ t('移除') }}</button>
      </div>
    </div>

    <p v-if="!list.length" class="text-muted" style="padding:8px 0;">{{ t('还没有绑定邮箱，现在收信走的是家长的邮箱。') }}</p>
  </div>

  <form v-if="canAdd" class="settings-form" style="margin-top:16px;" @submit.prevent="onAdd">
    <div class="form-group">
      <label for="new_email">{{ t('添加邮箱') }}</label>
      <input id="new_email" v-model="newEmail" type="email" required :placeholder="t('请输入要添加的邮箱地址')">
    </div>
    <div class="form-group">
      <label for="email_add_password">{{ t('当前密码') }}</label>
      <input
        id="email_add_password"
        v-model="addPassword"
        type="password"
        name="password"
        :required="!recoveryMode"
        :placeholder="recoveryMode ? t('恢复流程中可留空') : t('输入当前密码以确认身份')"
      >
      <p v-if="recoveryMode" class="form-hint">{{ t('恢复流程中无需填写当前密码。') }}</p>
    </div>
    <button type="submit" id="email-add-btn" class="btn btn-primary" :disabled="adding">{{ adding ? t('发送中...') : t('发送验证邮件') }}</button>
    <small class="form-hint">{{ t('验证邮件会发到新邮箱，点里面的链接确认后才会生效') }}</small>
  </form>
  <p v-else class="form-hint" style="margin-top:16px;">{{ t('已经绑满 {:n} 个邮箱，要加新的请先移除一个。', { n: maxEmails }) }}</p>
</template>
