<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed, onMounted, ref } from 'vue'
import StepHead from '../StepHead.vue'
import CheckRow from '../CheckRow.vue'
import { t } from '../i18n'
import { probeRewrite } from '../api'
import { go, refreshEnv, wizard } from '../wizard'

const probing = ref(true)
const probe = ref('pending')

const required = computed(() => wizard.env?.required || [])
const optional = computed(() => wizard.env?.optional || [])
const envOk = computed(() => required.value.every((row) => row.pass))

const probeState = computed(() => {
  if (probing.value) return 'pending'
  return probe.value === 'ok' ? 'pass' : (probe.value === 'fail' ? 'fail' : 'off')
})
const probeText = computed(() => {
  if (probing.value) return t('Checking')
  return probe.value === 'ok' ? t('Pass') : (probe.value === 'fail' ? t('Needs attention') : t('Unknown'))
})
const probeHint = computed(() => {
  if (probing.value) return t('Probing /__rewrite_probe…')
  if (probe.value === 'ok') return t('/__rewrite_probe works')
  if (probe.value === 'fail') return t('Addresses other than the home page return 404 — set up the rules below and continue')
  return t('The probe failed. Click "Check again" and try again')
})

const snippet = `nginx:

location / {
    try_files $uri $uri/ /index.php$is_args$args;
}

${t('apache (public/.htaccess)')}:

RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]`

async function runProbe() {
  probing.value = true
  probe.value = await probeRewrite()
  wizard.probe = probe.value
  probing.value = false
}

async function recheck() {
  await refreshEnv()
  await runProbe()
}

onMounted(runProbe)
</script>

<template>
  <StepHead icon="env" :title="t('Check the environment')"
    :lead="t('First make sure this server can run Nomen. Fix the red items before continuing.')" />

  <div v-if="!envOk" class="alert alert--error" role="alert">{{ t('Fix the items marked "Needs attention", then refresh this page.') }}</div>

  <ul class="checks">
    <CheckRow v-for="row in required" :key="row.key" :label="row.label" :hint="row.pass ? '' : row.hint"
      :state="row.pass ? 'pass' : 'fail'" :state-text="row.pass ? t('Pass') : t('Needs attention')" />
    <CheckRow :label="t('Routing and URL rewriting')" :hint="probeHint" :state="probeState" :state-text="probeText" />
  </ul>

  <div v-if="probe === 'fail' && !probing" class="alert alert--error" style="margin-top:18px">
    <strong>{{ t('URL rewriting is not active.') }}</strong>
    {{ t('Addresses other than the home page, such as /login and /special/version, return 404. Add the rules below, reload, then click "Check again".') }}
    <p style="margin:10px 0 0">
      {{ t('You can also continue without it: the site will use index.php?page= links, and you can switch later in config/installed.php.') }}
    </p>
    <pre class="code">{{ snippet }}</pre>
  </div>

  <div class="actions">
    <button v-if="envOk" class="btn btn-primary" type="button" @click="go('db')">{{ t('Continue') }}</button>
    <button v-if="!envOk || probe !== 'ok'" class="btn btn-ghost" type="button" :class="{ 'is-busy': wizard.busy }"
      :disabled="wizard.busy" @click="recheck">{{ t('Check again') }}</button>
  </div>

  <details class="more">
    <summary>{{ t('Optional (the site installs without them; the matching features are disabled)') }}</summary>
    <ul class="checks">
      <CheckRow v-for="row in optional" :key="row.key" :label="row.label" :hint="row.hint"
        :state="row.pass ? 'pass' : 'off'" :state-text="row.pass ? t('Available') : t('Skipped')" />
    </ul>
  </details>

  <div class="nav-links">
    <button class="linkish" type="button" @click="go('start')">← {{ t('Back') }}</button>
    <span></span>
  </div>
</template>
