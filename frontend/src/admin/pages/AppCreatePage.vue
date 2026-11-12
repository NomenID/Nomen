<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiGet, apiPostForm } from '../../app/api'
import { useT } from '../../composables/useT.js'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminAlert from '../components/AdminAlert.vue'
import AppForm from '../components/AppForm.vue'
import { copyText } from '../clipboard'

const { t } = useT()
const router = useRouter()
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const availableScopes = ref({})
const created = ref(null)

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

async function load() {
  try {
    const data = await apiGet('/api/admin/apps/create')
    availableScopes.value = data.availableScopes || {}
  } catch (err) {
    error.value = t('Could not load the form configuration')
  } finally {
    loading.value = false
  }
}

async function submit() {
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

    const result = await apiPostForm('/admin/apps/create', fd)
    if (result.success) {
      created.value = result
      window.Nomen.toast(t('App created'), 'success')
    } else {
      error.value = result.message || t('Create failed')
    }
  } finally {
    saving.value = false
  }
}

async function copy(value, label) {
  const ok = await copyText(value)
  window.Nomen.toast(ok ? t('{:label} copied', { label }) : t('Copy failed, select it manually'), ok ? 'success' : 'error')
}

onMounted(load)
</script>

<template>
  <AdminPage :title="t('New app')" :description="t('The client secret is shown only once after creation.')">
    <template #actions>
      <router-link class="a-btn a-btn--ghost" to="/admin/apps">{{ t('Back to list') }}</router-link>
    </template>

    <div v-if="loading" class="a-loading">{{ t('Loading…') }}</div>

    <template v-else>
      <AdminCard v-if="created" :title="t('App created')">
        <AdminAlert type="warning">{{ t('The client secret is shown only this once. It cannot be viewed again after you leave this page.') }}</AdminAlert>
        <dl class="a-kv">
          <dt>Client ID</dt>
          <dd><code>{{ created.client_id }}</code> <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="copy(created.client_id, 'Client ID')">{{ t('Copy') }}</button></dd>
          <dt>Client Secret</dt>
          <dd><code>{{ created.client_secret }}</code> <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="copy(created.client_secret, 'Client Secret')">{{ t('Copy') }}</button></dd>
        </dl>
        <button class="a-btn a-btn--primary" type="button" @click="router.push(`/admin/apps/${created.client_id}`)">{{ t('I saved it, view the app') }}</button>
      </AdminCard>

      <AdminCard v-else :title="t('Basic information')">
        <AdminAlert v-if="error" type="danger" :message="error" />
        <form class="a-form" @submit.prevent="submit">
          <AppForm v-model="form" :available-scopes="availableScopes" />
          <div class="a-form__actions">
            <button class="a-btn a-btn--primary" type="submit" :disabled="saving">{{ saving ? t('Creating…') : t('Create app') }}</button>
            <router-link class="a-btn a-btn--ghost" to="/admin/apps">{{ t('Cancel') }}</router-link>
          </div>
        </form>
      </AdminCard>
    </template>
  </AdminPage>
</template>
