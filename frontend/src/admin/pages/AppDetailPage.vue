<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet, apiPost, apiPostForm } from '../../app/api'
import { useT } from '../../composables/useT.js'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminAlert from '../components/AdminAlert.vue'
import AppForm from '../components/AppForm.vue'
import { copyText } from '../clipboard'

const { t } = useT()
const route = useRoute()
const router = useRouter()
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const data = ref(null)
const newSecret = ref('')
const clientId = computed(() => String(route.params.clientId))

const form = reactive({
  name: '',
  description: '',
  homepage: '',
  redirect_uris: '',
  client_type: 'confidential',
  pkce_required: false,
  scopes: ['openid'],
  iconFile: null,
})

function fillForm(payload) {
  form.name = payload.app?.name || ''
  form.description = payload.app?.description || ''
  form.homepage = payload.app?.homepage_url || ''
  form.redirect_uris = (payload.redirectUris || []).join('\n')
  form.client_type = payload.app?.client_type || 'confidential'
  form.pkce_required = Number(payload.app?.pkce_required) === 1
  form.scopes = payload.appScopes || ['openid']
  form.iconFile = null
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await apiGet(`/api/admin/apps/${clientId.value}`)
    fillForm(data.value)
  } catch (err) {
    error.value = t('The app does not exist or could not be loaded')
  } finally {
    loading.value = false
  }
}

async function save() {
  error.value = ''
  saving.value = true
  try {
    const fd = new FormData()
    fd.set('name', form.name)
    fd.set('description', form.description)
    fd.set('homepage', form.homepage)
    fd.set('redirect_uris', form.redirect_uris)
    fd.set('client_type', form.client_type)
    fd.set('pkce_required', form.pkce_required ? '1' : '')
    for (const scope of form.scopes) fd.append('scopes[]', scope)
    if (form.iconFile) fd.set('icon', form.iconFile)

    const result = await apiPostForm(`/admin/apps/${clientId.value}/update`, fd)
    if (result.success) {
      window.Nomen.toast(result.message, 'success')
      await load()
    } else {
      error.value = result.message || t('Save failed')
    }
  } finally {
    saving.value = false
  }
}

async function resetSecret() {
  const ok = await window.Nomen.confirm(
    t('Reset secret'),
    t('Resetting invalidates the old secret and every token already issued. The integrator must update their configuration. Continue?'),
    { confirmText: t('Reset'), cancelText: t('Cancel') },
  )
  if (!ok) return
  const result = await apiPost(`/admin/apps/${clientId.value}/reset-secret`, {})
  if (result.success) {
    newSecret.value = result.client_secret
    window.Nomen.toast(result.message, 'success')
  } else {
    window.Nomen.toast(result.message || t('Reset failed'), 'error')
  }
}

async function remove() {
  const ok = await window.Nomen.confirm(
    t('Delete app'),
    t('Deleting {:name} cannot be undone and revokes every token already issued.', { name: data.value?.app?.name || clientId.value }),
    { confirmText: t('Delete'), cancelText: t('Cancel') },
  )
  if (!ok) return
  const result = await apiPost(`/admin/apps/${clientId.value}/delete`, {})
  if (result.success) {
    window.Nomen.toast(result.message, 'success')
    router.push('/admin/apps')
  } else {
    window.Nomen.toast(result.message || t('Delete failed'), 'error')
  }
}

async function toggleStatus() {
  const result = await apiPost(`/admin/apps/${clientId.value}/toggle`, {})
  if (result.success) {
    window.Nomen.toast(result.message, 'success')
    await load()
  } else {
    window.Nomen.toast(result.message || t('Operation failed'), 'error')
  }
}

async function copy(value, label) {
  const ok = await copyText(value)
  window.Nomen.toast(ok ? t('{:label} copied', { label }) : t('Copy failed, select it manually'), ok ? 'success' : 'error')
}

onMounted(load)
</script>

<template>
  <AdminPage :title="data ? t('App · {:name}', { name: data.app.name }) : t('App details')" :description="t('Redirect URIs, scopes, and examples for integrators.')">
    <template #actions>
      <router-link class="a-btn a-btn--ghost" to="/admin/apps">{{ t('Back to list') }}</router-link>
      <button v-if="data" class="a-btn a-btn--ghost" type="button" @click="toggleStatus">
        {{ Number(data.app.status) === 1 ? t('Disable app') : t('Enable app') }}
      </button>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">{{ t('Loading…') }}</div>

    <template v-else-if="data">
      <div class="a-profile">
        <img v-if="data.app.icon" :src="`/assets/develops/${data.app.icon}`" alt="" class="a-profile__avatar a-profile__avatar--img">
        <div v-else class="a-profile__avatar">{{ (data.app.name || '?').slice(0, 1).toUpperCase() }}</div>
        <div>
          <h2>{{ data.app.name }}</h2>
          <p class="a-text a-text--muted">{{ data.app.description || t('No description yet') }}</p>
          <div class="a-badges">
            <AdminBadge :tone="Number(data.app.status) === 1 ? 'success' : 'danger'">{{ Number(data.app.status) === 1 ? t('Enabled') : t('Disabled') }}</AdminBadge>
            <AdminBadge>{{ data.isPublic ? t('Public client') : t('Confidential client') }}</AdminBadge>
            <AdminBadge v-if="data.pkceOn" tone="primary">PKCE</AdminBadge>
          </div>
        </div>
      </div>

      <div class="a-statgrid a-statgrid--compact">
        <div class="a-stat"><div class="a-stat__value">{{ data.stats.authorized_users }}</div><div class="a-stat__label">{{ t('Authorized users') }}</div></div>
        <div class="a-stat"><div class="a-stat__value">{{ data.stats.active_tokens }}</div><div class="a-stat__label">{{ t('Active tokens') }}</div></div>
        <div class="a-stat"><div class="a-stat__value">{{ data.app.created_at }}</div><div class="a-stat__label">{{ t('Created at') }}</div></div>
        <div class="a-stat"><div class="a-stat__value">{{ data.app.owner_username || data.app.developer_name || '—' }}</div><div class="a-stat__label">{{ t('Owner') }}</div></div>
      </div>

      <AdminCard :title="t('Credentials')">
        <dl class="a-kv">
          <dt>Client ID</dt>
          <dd><code>{{ data.app.client_id }}</code> <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="copy(data.app.client_id, 'Client ID')">{{ t('Copy') }}</button></dd>
          <dt>Client Secret</dt>
          <dd>
            <span v-if="newSecret"><code>{{ newSecret }}</code> <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="copy(newSecret, 'Client Secret')">{{ t('Copy') }}</button></span>
            <span v-else class="a-text--muted">{{ t('Saved (it will not be shown again)') }}</span>
            <button v-if="!data.isPublic" class="a-btn a-btn--ghost a-btn--sm" type="button" @click="resetSecret">{{ t('Reset secret') }}</button>
          </dd>
        </dl>
        <AdminAlert v-if="newSecret" type="warning">{{ t('The new secret is shown only this once. It cannot be viewed after you close the page.') }}</AdminAlert>
      </AdminCard>

      <AdminCard :title="t('App information')">
        <form class="a-form" @submit.prevent="save">
          <AppForm v-model="form" :available-scopes="data.availableScopes" :current-icon="data.app.icon || ''" />
          <div class="a-form__actions">
            <button class="a-btn a-btn--primary" type="submit" :disabled="saving">{{ saving ? t('Saving…') : t('Save changes') }}</button>
          </div>
        </form>
      </AdminCard>

      <AdminCard :title="t('Integration example')">
        <p class="a-text a-text--muted">{{ t('Authorization URL') }}</p>
        <pre class="a-code">{{ data.authorizeSample }}</pre>
        <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="copy(data.authorizeSample, t('Authorization URL'))">{{ t('Copy') }}</button>

        <p class="a-text a-text--muted">{{ t('Token request') }}</p>
        <pre class="a-code">{{ data.tokenSample }}</pre>
        <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="copy(data.tokenSample, t('Token request'))">{{ t('Copy') }}</button>
      </AdminCard>

      <AdminCard :title="t('Danger zone')">
        <p class="a-text">{{ t('Deleting an app revokes every user authorization and token. It cannot be undone.') }}</p>
        <button class="a-btn a-btn--danger" type="button" @click="remove">{{ t('Delete app') }}</button>
      </AdminCard>
    </template>
  </AdminPage>
</template>
