<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

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
    throw new Error(t('Server returned {:status}', { status: response.status }))
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
    toast(t('Enter an email address'), 'warning')
    return
  }
  if (!props.recoveryMode && !addPassword.value) {
    toast(t('Enter the current password'), 'warning')
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
    toast(err.message || t('Network error'), 'error')
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
    toast(err.message || t('Network error'), 'error')
  } finally {
    resendingId.value = 0
  }
}

function onPrimary(row) {
  promptPassword(t('Set as primary'), t('Set {:email} as your contact address and primary sign-in email.', { email: row.email }), false, row.id, urls.primary)
}

function onRemove(row) {
  promptPassword(t('Remove email'), t('After removing {:email}, this address can no longer be used to sign in.', { email: row.email }), true, row.id, urls.remove)
}

function promptPassword(title, message, danger, emailId, url) {
  if (!window.Nomen || typeof window.Nomen.prompt !== 'function') return
  window.Nomen.prompt(title, message, {
    inputType: 'password',
    placeholder: props.recoveryMode ? t('Can be left empty in recovery mode') : t('Current password'),
    required: !props.recoveryMode,
    confirmText: danger ? t('Remove') : t('Set as primary'),
    danger,
  }).then(async (password) => {
    if (password === null) return
    try {
      const data = await post(url, { email_id: emailId, password })
      applyResult(data)
    } catch (err) {
      toast(err.message || t('Network error'), 'error')
    }
  })
}
</script>

<template>
  <div class="email-list" :data-recovery-mode="recoveryMode ? '1' : '0'">
    <div v-for="row in list" :key="row.id" class="email-item" :data-email-id="row.id">
      <div class="email-item__main">
        <span class="email-item__addr">{{ row.email }}</span>
        <span v-if="row.is_primary" class="badge badge--info">{{ t('Primary') }}</span>
        <span v-if="row.verified" class="badge badge--success">{{ t('Verified') }}</span>
        <span v-else class="badge badge--warning">{{ t('Pending') }}</span>
      </div>
      <div class="email-item__actions">
        <button
          v-if="!row.verified"
          type="button"
          class="btn btn-sm btn-secondary"
          :disabled="resendingId === row.id"
          @click="onResend(row)"
        >{{ resendingId === row.id ? t('Sending...') : t('Resend verification email') }}</button>
        <button
          v-else-if="!row.is_primary"
          type="button"
          class="btn btn-sm btn-secondary"
          @click="onPrimary(row)"
        >{{ t('Set as primary') }}</button>
        <button
          v-if="list.length > 1"
          type="button"
          class="btn btn-sm btn-danger-outline"
          @click="onRemove(row)"
        >{{ t('Remove') }}</button>
      </div>
    </div>

    <p v-if="!list.length" class="text-muted" style="padding:8px 0;">{{ t('No email bound yet — notifications currently go to a parent\'s address.') }}</p>
  </div>

  <form v-if="canAdd" class="settings-form" style="margin-top:16px;" @submit.prevent="onAdd">
    <div class="form-group">
      <label for="new_email">{{ t('Add email') }}</label>
      <input id="new_email" v-model="newEmail" type="email" required :placeholder="t('Enter the address to add')">
    </div>
    <div class="form-group">
      <label for="email_add_password">{{ t('Current password') }}</label>
      <input
        id="email_add_password"
        v-model="addPassword"
        type="password"
        name="password"
        :required="!recoveryMode"
        :placeholder="recoveryMode ? t('Can be left empty in recovery mode') : t('Enter the current password to confirm it\'s you')"
      >
      <p v-if="recoveryMode" class="form-hint">{{ t('No current password needed in recovery mode.') }}</p>
    </div>
    <button type="submit" id="email-add-btn" class="btn btn-primary" :disabled="adding">{{ adding ? t('Sending...') : t('Send verification email') }}</button>
    <small class="form-hint">{{ t('The verification mail goes to the new address; the change applies after you click its link') }}</small>
  </form>
  <p v-else class="form-hint" style="margin-top:16px;">{{ t('You\'ve bound the maximum of {:n} emails — remove one before adding another.', { n: maxEmails }) }}</p>
</template>
