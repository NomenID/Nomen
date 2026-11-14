<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { apiGet, apiPost } from '../../app/api'
import { useT } from '../../composables/useT.js'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminAlert from '../components/AdminAlert.vue'

const { t } = useT()
const loading = ref(true)
const saving = ref(false)
const clearing = ref(false)
const error = ref('')
const data = ref(null)
const form = reactive({
  login_2fa: 'off',
  cache_backend: 'auto',
  api_nomen: '1',
  policies: [],
  additional_terms: [],
})

function emptyPolicy(index = 0) {
  return { role: index === 0 ? 'privacy' : (index === 1 ? 'terms' : 'other'), label: '', url: '' }
}

function emptyTerm() {
  return { label: '', url: '' }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await apiGet('/api/admin/settings')
    form.login_2fa = data.value.policy
    form.cache_backend = data.value.backend
    form.api_nomen = data.value.apiEnabled ? '1' : '0'
    form.policies = [0, 1, 2].map((i) => {
      const policy = data.value.policies?.[i] || {}
      return { role: policy.role || emptyPolicy(i).role, label: policy.label || '', url: policy.url || '' }
    })
    form.additional_terms = (data.value.additionalTerms || []).map((term) => ({ label: term.label, url: term.url }))
  } catch (err) {
    error.value = t('Could not load the settings')
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    const result = await apiPost('/admin/settings', {
      login_2fa: form.login_2fa,
      cache_backend: form.cache_backend,
      api_nomen: form.api_nomen,
      policies: JSON.stringify(form.policies.filter((policy) => policy.url.trim())),
      additional_terms: JSON.stringify(form.additional_terms.filter((term) => term.label.trim() && term.url.trim())),
    })
    if (result.success) {
      window.Nomen.toast(result.message, 'success')
      await load()
    } else {
      window.Nomen.toast(result.message || t('Save failed'), 'error')
    }
  } finally {
    saving.value = false
  }
}

async function clearCache() {
  const ok = await window.Nomen.confirm(
    t('Clear cache'),
    t('Clear this site\'s cache? It is rebuilt on the next request.'),
    { confirmText: t('Clear'), cancelText: t('Cancel') },
  )
  if (!ok) return

  clearing.value = true
  try {
    const result = await apiPost('/admin/settings/cache/clear')
    if (result.success) {
      window.Nomen.toast(t('Cleared {:count} entries', { count: result.deleted }), 'success')
    } else {
      window.Nomen.toast(result.message || t('This backend does not support clearing'), 'error')
    }
  } catch (err) {
    window.Nomen.toast(t('This backend does not support clearing'), 'error')
  } finally {
    clearing.value = false
  }
}

onMounted(load)
</script>

<template>
  <AdminPage :title="t('Settings')" :description="t('Site-level security and caching policy.')">
    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">{{ t('Loading…') }}</div>

    <template v-else-if="data">
      <AdminAlert v-if="!data.settingsReady" type="warning">
        {{ t('The database has not been upgraded yet (the site_settings table is missing). Run {:cmd} first.', { cmd: 'php bin/nomen upgrade' }) }}
      </AdminAlert>

      <form @submit.prevent="save">
        <AdminCard :title="t('Two-step verification at sign-in')">
          <p class="a-card__desc">{{ t('Ask users for a second step after the password. This is the site default; each user can override it.') }}</p>
          <div class="a-radios">
            <label v-for="(label, value) in data.login2faOptions" :key="value" class="a-radio" :class="{ 'is-active': form.login_2fa === value }">
              <input v-model="form.login_2fa" type="radio" :value="value">
              <span>{{ label }}</span>
            </label>
          </div>
        </AdminCard>

        <AdminCard :title="t('Cache')">
          <p class="a-card__desc">
            {{ t('Currently in use:') }} <AdminBadge tone="primary">{{ data.effectiveBackend }}</AdminBadge>
            <span v-if="data.backend === 'auto'" class="a-text--muted">{{ t('(Auto mode prefers Redis and falls back to files when Redis is unavailable.)') }}</span>
          </p>
          <select v-model="form.cache_backend" class="a-input a-input--narrow">
            <option v-for="(label, value) in data.cacheOptions" :key="value" :value="value">{{ label }}</option>
          </select>
          <p class="a-card__desc">
            {{ t('Only this site\'s keys are removed; other applications sharing the same Redis are untouched.') }}
          </p>
          <button class="a-btn" type="button" :disabled="clearing" @click="clearCache">
            {{ clearing ? t('Clearing…') : t('Clear cache') }}
          </button>
        </AdminCard>

        <AdminCard :title="t('Site API')">
          <p class="a-card__desc">
            {{ t('Read-only API at {:endpoint}. Actions go in the query string, for example {:example}. Site info and documentation pages are public; modules a plugin marks as sensitive require an administrator.', { endpoint: data.apiEndpoint, example: 'action=query&meta=siteinfo' }) }}
          </p>
          <label class="a-check">
            <input v-model="form.api_nomen" type="checkbox" true-value="1" false-value="0">
            <span>{{ t('Turn on the site API') }}</span>
          </label>
        </AdminCard>

        <AdminCard :title="t('Policies')">
          <div v-for="(policy, i) in form.policies" :key="'policy-' + i" class="a-link-row">
            <select v-model="policy.role" class="a-input">
              <option value="privacy">{{ t('Privacy Policy') }}</option>
              <option value="terms">{{ t('Terms of Service') }}</option>
              <option value="other">{{ t('Other') }}</option>
            </select>
            <input v-model="policy.label" class="a-input" type="text" :placeholder="t('Label')">
            <input v-model="policy.url" class="a-input" type="url" :placeholder="t('URL')">
          </div>
          <p class="a-card__desc">{{ t('Additional terms') }}</p>
          <div v-for="(term, i) in form.additional_terms" :key="'term-' + i" class="a-link-row a-link-row--terms">
            <input v-model="term.label" class="a-input" type="text" :placeholder="t('Label')">
            <input v-model="term.url" class="a-input" type="url" :placeholder="t('URL')">
            <button class="a-btn a-btn--ghost" type="button" @click="form.additional_terms.splice(i, 1)">{{ t('Remove') }}</button>
          </div>
          <button class="a-btn" type="button" @click="form.additional_terms.push(emptyTerm())">{{ t('Add') }}</button>
        </AdminCard>

        <AdminCard :title="t('Email')">
          <p class="a-text">
            {{ t('Mail is configured:') }} <AdminBadge :tone="data.mailConfigured ? 'success' : 'danger'">{{ data.mailConfigured ? t('Configured') : t('Not configured') }}</AdminBadge>
          </p>
          <p class="a-text a-text--muted">{{ t('Mail settings live in config/email.php and .env. Changes apply immediately; there is nothing to save here.') }}</p>
        </AdminCard>

        <div class="a-form__actions">
          <button class="a-btn a-btn--primary" type="submit" :disabled="saving">{{ saving ? t('Saving…') : t('Save settings') }}</button>
        </div>
      </form>
    </template>
  </AdminPage>
</template>
