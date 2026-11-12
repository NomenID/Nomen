<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed } from 'vue'
import StepHead from '../StepHead.vue'
import { t } from '../i18n'
import { go, languageName, pluginName, resetStep, submit, wizard } from '../wizard'

const site = computed(() => wizard.state.site || {})
const db = computed(() => wizard.state.db || {})
const admin = computed(() => wizard.state.admin || {})
const smtp = computed(() => wizard.state.smtp || {})
const features = computed(() => wizard.state.features || {})
const selectedPlugins = computed(() => Array.isArray(wizard.state.plugins) ? wizard.state.plugins : [])

const dbLabel = computed(() => {
  if (db.value.driver === 'sqlite') {
    return 'SQLite · ' + (db.value.path || '')
  }
  return `${db.value.user || ''}@${db.value.host || ''}:${db.value.port || ''}/${db.value.name || ''}`
})

const pluginLabel = computed(() => selectedPlugins.value.length
  ? selectedPlugins.value.map(pluginName).join('、')
  : t('None enabled'))

const featureLabel = computed(() => {
  const names = []
  if (features.value.guardians) names.push(t('Family and minor guardianship'))
  if (features.value.country) names.push(t('Ask for country / region at registration'))
  return names.length ? names.join('、') : t('None enabled')
})

const logoLabel = computed(() => {
  const logo = site.value.logo || ''
  if (logo === '') return t('Default Nomen icon')
  return logo.startsWith('/assets/develops/') ? t('Uploaded to the site') : logo
})

async function install() {
  const data = await submit('install', {})
  if (data) {
    go('done')
  }
}
</script>

<template>
  <StepHead icon="review" :title="t('Review')"
    :lead="t('One last look. The database password is not shown here.')" />

  <dl class="summary">
    <div><dt>{{ t('Site') }}</dt><dd>{{ site.name }} · {{ site.base_url }}</dd></div>
    <div><dt>{{ t('Site icon') }}</dt><dd>{{ logoLabel }}</dd></div>
    <div><dt>{{ t('Database') }}</dt><dd>{{ dbLabel }}</dd></div>
    <div><dt>{{ t('Admin account') }}</dt><dd>{{ admin.username }} · {{ admin.email }}</dd></div>
    <div>
      <dt>{{ t('Default language') }}</dt>
      <dd>{{ languageName(site.language_default) }}{{ site.language_allow_switch ? t(' (visitors can switch)') : t(' (fixed)') }}</dd>
    </div>
    <div><dt>{{ t('Extensions') }}</dt><dd>{{ pluginLabel }}</dd></div>
    <div><dt>{{ t('Optional features') }}</dt><dd>{{ featureLabel }}</dd></div>
    <div><dt>{{ t('Email') }}</dt><dd>{{ smtp.host ? smtp.host : t('Not configured') }}</dd></div>
  </dl>

  <div class="actions">
    <button class="btn btn-primary btn-lg" type="button" :class="{ 'is-busy': wizard.busy }"
      :disabled="wizard.busy" @click="install">{{ t('Start installation') }}</button>
  </div>

  <div class="nav-links">
    <button class="linkish" type="button" :disabled="wizard.busy" @click="go('mail')">← {{ t('Back') }}</button>
    <template v-if="wizard.error">
      <button class="linkish" type="button" :disabled="wizard.busy" @click="go('db')">{{ t('Use a different empty database') }}</button>
      <button class="linkish" type="button" :disabled="wizard.busy" @click="resetStep">{{ t('Start over') }}</button>
    </template>
    <span v-else></span>
  </div>
</template>
