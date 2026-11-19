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
const checking = ref(false)
const error = ref('')
const data = ref(null)
const form = reactive({
  login_2fa: 'off',
  cache_backend: 'auto',
  api_nomen: '1',
  update_auto: '0',
  update_channel: 'minor',
  update_notify_email: '',
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
    form.update_auto = data.value.autoUpdate?.enabled ? '1' : '0'
    form.update_channel = data.value.autoUpdate?.channel || 'minor'
    form.update_notify_email = data.value.autoUpdate?.notifyEmail || ''
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
      update_auto: form.update_auto,
      update_channel: form.update_channel,
      update_notify_email: form.update_notify_email,
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

function formatted(ts) {
  return ts ? new Date(ts * 1000).toLocaleString() : t('Never')
}

function scheduleText(task) {
  if (task.dailyAt) return t('Daily at {:time}', { time: task.dailyAt })
  const minutes = Math.round((task.every || 0) / 60)
  return minutes <= 1 ? t('Every minute') : t('Every {:count} min', { count: minutes })
}

function tickStale(ts) {
  return ts > 0 && Date.now() / 1000 - ts > 180
}

async function runTask(task) {
  try {
    const result = await apiPost('/admin/settings/schedule/run', { task: task.id })
    if (result.success) {
      window.Nomen.toast(t('Queued. It will run on the next tick (within a minute).'), 'success')
      await load()
    } else {
      window.Nomen.toast(result.message || t('Save failed'), 'error')
    }
  } catch (err) {
    window.Nomen.toast(t('Save failed'), 'error')
  }
}

async function checkUpdates() {
  checking.value = true
  try {
    const result = await apiPost('/admin/settings/update/check')
    if (result.success) {
      window.Nomen.toast(result.result?.message || t('Check complete'), result.result?.status === 'current' ? 'success' : 'info')
      await load()
    } else {
      window.Nomen.toast(result.message || t('Check failed'), 'error')
    }
  } catch (err) {
    window.Nomen.toast(t('Check failed'), 'error')
  } finally {
    checking.value = false
  }
}

async function resetUpdate() {
  const ok = await window.Nomen.confirm(
    t('Reset the failed update state'),
    t('The updater will try the same version again on the next check.'),
    { confirmText: t('Reset'), cancelText: t('Cancel') },
  )
  if (!ok) return

  try {
    const result = await apiPost('/admin/settings/update/reset')
    if (result.success) {
      window.Nomen.toast(result.message, 'success')
      await load()
    } else {
      window.Nomen.toast(result.message || t('Save failed'), 'error')
    }
  } catch (err) {
    window.Nomen.toast(t('Save failed'), 'error')
  }
}

function logLine(entry) {
  return `${entry.ts || ''} ${(entry.level || '').toUpperCase()} ${entry.step || ''}: ${entry.message || ''}`.trim()
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

        <AdminCard :title="t('Automatic updates')">
          <p class="a-card__desc">
            {{ t('Downloads official releases in the background, verifies the SHA-256, keeps a backup, and rolls back if anything goes wrong. The scheduled task must be running.') }}
          </p>

          <AdminAlert v-if="!data.autoUpdate.zipAvailable || !data.autoUpdate.curlAvailable" type="warning">
            {{ t('Automatic updates need the PHP zip and curl extensions.') }}
          </AdminAlert>
          <AdminAlert v-else-if="!data.autoUpdate.writable" type="warning">
            {{ t('The web server cannot write to the site directory, so automatic updates are unavailable.') }}
          </AdminAlert>
          <AdminAlert v-else-if="!data.autoUpdate.phpOk" type="warning">
            {{ t('This release needs a newer PHP version.') }}
          </AdminAlert>

          <label class="a-check">
            <input v-model="form.update_auto" type="checkbox" true-value="1" false-value="0">
            <span>{{ t('Enable automatic updates') }}</span>
          </label>

          <p class="a-card__desc">{{ t('Update channel') }}</p>
          <select v-model="form.update_channel" class="a-input a-input--narrow">
            <option value="patch">{{ t('Patch releases only (x.y.z)') }}</option>
            <option value="minor">{{ t('Same major version (x.y)') }}</option>
            <option value="all">{{ t('All releases') }}</option>
          </select>

          <p class="a-card__desc">{{ t('Notification email') }}</p>
          <input v-model="form.update_notify_email" class="a-input" type="email" :placeholder="data.autoUpdate.effectiveNotifyEmail || t('Administrator address')">
          <p class="a-card__desc">{{ t('Leave empty to use the administrator address.') }}</p>

          <p class="a-text">
            {{ t('Current version:') }} <code class="a-mono">{{ data.autoUpdate.currentVersion }}</code>
            <template v-if="data.autoUpdate.latest"> · {{ t('Latest:') }} <code class="a-mono">{{ data.autoUpdate.latest }}</code></template>
          </p>
          <p class="a-text a-text--muted">
            {{ t('Last check:') }} {{ formatted(data.autoUpdate.lastCheckAt) }}
            <template v-if="data.autoUpdate.lastCheckError"> — {{ data.autoUpdate.lastCheckError }}</template>
          </p>

          <AdminAlert v-if="data.autoUpdate.failed" type="warning">
            {{ t('The last attempt for {:version} failed: {:error}', { version: data.autoUpdate.failed.version, error: data.autoUpdate.failed.reason }) }}
          </AdminAlert>
          <p v-else-if="data.autoUpdate.pending && !data.autoUpdate.enabled" class="a-text">
            {{ t('Version {:version} is available. Turn on automatic updates to install it in the background.', { version: data.autoUpdate.pending }) }}
          </p>
          <p v-else-if="data.autoUpdate.pending" class="a-text">
            {{ t('Version {:version} will be installed by the next scheduled check.', { version: data.autoUpdate.pending }) }}
          </p>

          <div class="a-form__actions">
            <button class="a-btn" type="button" :disabled="checking" @click="checkUpdates">
              {{ checking ? t('Checking…') : t('Check now') }}
            </button>
            <button v-if="data.autoUpdate.failed" class="a-btn a-btn--ghost" type="button" @click="resetUpdate">
              {{ t('Reset the failed update state') }}
            </button>
          </div>

          <p class="a-text">
            {{ t('Mail is configured:') }}
            <AdminBadge :tone="data.autoUpdate.mailConfigured ? 'success' : 'danger'">
              {{ data.autoUpdate.mailConfigured ? t('Configured') : t('Not configured') }}
            </AdminBadge>
          </p>

          <details v-if="data.autoUpdate.logTail?.length" class="a-log">
            <summary>{{ t('Update log') }}</summary>
            <pre class="a-code">{{ data.autoUpdate.logTail.map(logLine).join('\n') }}</pre>
          </details>
        </AdminCard>

        <AdminCard :title="t('Scheduled tasks')">
          <p class="a-card__desc">{{ t('Background tasks registered by the core and plugins. Without the cron entry everything still works; tasks just wait until you run them by hand.') }}</p>

          <AdminAlert v-if="!data.schedule.ready" type="warning">
            {{ t('The scheduled_tasks table is missing. Run {:cmd} first.', { cmd: 'php bin/nomen upgrade' }) }}
          </AdminAlert>
          <AdminAlert v-else-if="data.schedule.lastTick === 0" type="warning">
            {{ t('The scheduler has not run yet. Add the cron entry below.') }}
          </AdminAlert>
          <AdminAlert v-else-if="tickStale(data.schedule.lastTick)" type="warning">
            {{ t('Cron is not running (last tick: {:time}).', { time: formatted(data.schedule.lastTick) }) }}
          </AdminAlert>
          <p v-else class="a-text--muted">{{ t('Last tick: {:time}', { time: formatted(data.schedule.lastTick) }) }}</p>

          <p class="a-text">{{ t('Add this line to the server\'s crontab:') }}</p>
          <code class="a-code">{{ data.schedule.crontab }}</code>

          <div v-if="data.schedule.tasks.length" class="a-table-wrap">
            <table class="a-table">
              <thead>
                <tr>
                  <th>{{ t('Task') }}</th>
                  <th>{{ t('Schedule') }}</th>
                  <th>{{ t('Last run') }}</th>
                  <th>{{ t('Next run') }}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="task in data.schedule.tasks" :key="task.id">
                  <td>
                    <strong>{{ task.label }}</strong>
                    <div class="a-mono a-text--muted">{{ task.id }}</div>
                  </td>
                  <td>{{ scheduleText(task) }}</td>
                  <td>{{ formatted(task.lastRun) }}</td>
                  <td>{{ formatted(task.nextRun) }}</td>
                  <td>
                    <AdminBadge v-if="task.status === 'error'" tone="danger">{{ t('Failed') }}</AdminBadge>
                    <AdminBadge v-else-if="task.status === 'running'" tone="primary">{{ t('Running') }}</AdminBadge>
                    <AdminBadge v-else-if="task.status === 'ok'" tone="success">{{ t('OK') }}</AdminBadge>
                    <button class="a-btn a-btn--sm" type="button" @click="runTask(task)">{{ t('Run now') }}</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
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
