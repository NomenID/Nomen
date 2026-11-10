<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed, ref } from 'vue'
import { i18n, t } from './i18n'
import { fetchI18n } from './api'
import { initWizard, wizard } from './wizard'
import StartStep from './steps/StartStep.vue'
import EnvStep from './steps/EnvStep.vue'
import DbStep from './steps/DbStep.vue'
import SiteStep from './steps/SiteStep.vue'
import AdminStep from './steps/AdminStep.vue'
import PluginsStep from './steps/PluginsStep.vue'
import MailStep from './steps/MailStep.vue'
import ReviewStep from './steps/ReviewStep.vue'
import DoneStep from './steps/DoneStep.vue'

const props = defineProps({
  csrf: { type: String, default: '' },
  step: { type: String, default: 'start' },
  resume: { type: String, default: 'env' },
  started: { type: Boolean, default: false },
  state: { type: Object, default: () => ({}) },
  languages: { type: Array, default: () => [] },
  plugins: { type: Array, default: () => [] },
  env: { type: Object, default: () => ({ required: [], optional: [], all: true }) },
  upload: { type: Object, default: () => ({ enabled: false, reason: '' }) },
  baseUrl: { type: String, default: '' },
  defaultLogo: { type: String, default: '/assets/brand/nomen-icon.svg' },
  done: { type: Object, default: null },
  lang: { type: String, default: 'en' },
})

initWizard(props)

const steps = {
  start: StartStep,
  env: EnvStep,
  db: DbStep,
  site: SiteStep,
  admin: AdminStep,
  plugins: PluginsStep,
  mail: MailStep,
  review: ReviewStep,
  done: DoneStep,
}

const current = computed(() => steps[wizard.step] || StartStep)
const brandLogo = computed(() => wizard.state?.site?.logo || wizard.defaultLogo)

const theme = ref(document.documentElement.dataset.theme === 'light' ? 'light' : 'dark')

function toggleTheme() {
  const next = theme.value === 'dark' ? 'light' : 'dark'
  theme.value = next
  document.documentElement.dataset.theme = next
  try {
    localStorage.setItem('nomen-install-theme', next)
  } catch (err) {
    // 隐私模式下存不了，只在这次会话里生效。
  }
}

const switchingLang = ref(false)

async function changeLanguage(event) {
  const target = event.target.value
  if (!target || target === i18n.locale || switchingLang.value) {
    event.target.value = i18n.locale
    return
  }
  switchingLang.value = true
  try {
    await fetchI18n(target)
  } catch (err) {
    switchingLang.value = false
    event.target.value = i18n.locale
    return
  }
  window.location.reload()
}
</script>

<template>
  <header class="topbar">
    <img class="topbar__logo" :src="brandLogo" alt="" width="26" height="26">
    <b>Nomen</b>
    <span class="topbar__tag">{{ t('Install') }}</span>
    <span class="topbar__spacer"></span>
    <select class="lang-select" :value="i18n.locale" :disabled="switchingLang"
      :aria-label="t('Language')" @change="changeLanguage">
      <option v-for="language in props.languages" :key="language.slug" :value="language.slug">
        {{ language.name }}
      </option>
    </select>
    <button class="theme-toggle" type="button" @click="toggleTheme"
      :aria-label="theme === 'dark' ? t('Switch to light') : t('Switch to dark')">
      <svg v-if="theme === 'dark'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="4.2" />
        <path d="M12 3v2.2M12 18.8V21M3 12h2.2M18.8 12H21M5.6 5.6l1.6 1.6M16.8 16.8l1.6 1.6M18.4 5.6l-1.6 1.6M7.2 16.8l-1.6 1.6" />
      </svg>
      <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M20 14.5A8.2 8.2 0 0 1 9.5 4a8.2 8.2 0 1 0 10.5 10.5Z" />
      </svg>
    </button>
  </header>

  <main class="stage">
    <section class="panel">
      <div class="panel__inner">
        <div v-if="wizard.error" class="alert alert--error" role="alert">{{ wizard.error }}</div>
        <Transition name="step" mode="out-in">
          <component :is="current" :key="wizard.step" />
        </Transition>
      </div>
    </section>
  </main>
</template>
